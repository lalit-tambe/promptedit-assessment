<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Services\Pixelforge\Exceptions\ConcurrencyLimitExceededException;
use App\Services\Pixelforge\Exceptions\ContentPolicyViolationException;
use App\Services\Pixelforge\Exceptions\DailyLimitExceededException;
use App\Services\Pixelforge\Exceptions\InvalidApiKeyException;
use App\Services\Pixelforge\Exceptions\OverloadedException;
use App\Services\Pixelforge\Exceptions\PixelforgeException;
use App\Services\Pixelforge\Exceptions\RateLimitExceededException;
use App\Services\Pixelforge\Exceptions\ValidationFailedException;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SubmitGenerationJob implements ShouldQueue
{
    use Queueable;

    public const MAX_TRANSIENT_RETRIES = 3;

    public const CONCURRENCY_RETRY_SECONDS = 5;

    public int $tries = 25;

    public readonly int $generationId;

    public function __construct(Generation|int $generation)
    {
        $this->generationId = $generation instanceof Generation ? $generation->id : $generation;
    }

    public function handle(PixelforgeClient $client): void
    {
        $generation = Generation::query()->find($this->generationId);

        if ($generation === null) {
            return;
        }

        // Never re-send a job that already has a Pixelforge ID or is already finished
        if ($generation->provider_id !== null || $generation->isFinal()) {
            return;
        }

        // Only allow 3 unfinished generations at Pixelforge at the same time; extras stay queued
        $maxConcurrent = (int) config('pixelforge.limits.max_concurrent', 3);
        $inFlightCount = Generation::query()
            ->whereNotNull('provider_id')
            ->whereIn('status', [Generation::STATUS_QUEUED, Generation::STATUS_PROCESSING])
            ->where('id', '!=', $generation->id)
            ->count();

        if ($inFlightCount >= $maxConcurrent) {
            $this->release(self::CONCURRENCY_RETRY_SECONDS);

            return;
        }

        try {
            $response = $client->createGeneration([
                'prompt' => $generation->prompt,
                'size' => $generation->size,
                'quality' => $generation->quality,
                'reference_images' => $generation->reference_urls ?? [],
                'metadata' => [
                    'generation_id' => (string) $generation->id,
                    'idempotency_key' => $generation->idempotency_key,
                ],
            ], $generation->idempotency_key);

            DB::transaction(function () use ($generation, $response): void {
                $locked = Generation::query()->lockForUpdate()->find($generation->id);

                if ($locked === null) {
                    return;
                }

                if ($locked->provider_id === null && isset($response['id']) && is_string($response['id'])) {
                    $locked->provider_id = $response['id'];
                }

                if ($locked->rates === null && isset($response['rates']) && is_array($response['rates'])) {
                    /** @var array<string, string> $rates */
                    $rates = $response['rates'];
                    $locked->rates = $rates;
                }

                $locked->save();
            });
        } catch (ConcurrencyLimitExceededException $e) {
            // Keep the generation queued and retry after Retry-After seconds
            $this->release($e->retryAfter ?? self::CONCURRENCY_RETRY_SECONDS);
        } catch (RateLimitExceededException|OverloadedException $e) {
            $this->handleTransientError($generation, $e);
        } catch (DailyLimitExceededException $e) {
            $this->markFailed($generation, 'daily_limit_exceeded', 'Daily limit reached, try tomorrow.');
        } catch (ContentPolicyViolationException $e) {
            $this->markFailed($generation, 'content_policy_violation', 'Prompt was rejected.');
        } catch (ValidationFailedException $e) {
            $this->markFailed($generation, 'validation_failed', $e->getMessage());
        } catch (InvalidApiKeyException $e) {
            Log::error('Pixelforge authentication failed due to invalid API key.', [
                'generation_id' => $generation->id,
                'message' => $e->getMessage(),
            ]);
            $this->markFailed($generation, 'invalid_api_key', $e->getMessage());
        } catch (PixelforgeException $e) {
            if ($e->statusCode >= 500) {
                $this->handleTransientError($generation, $e);

                return;
            }

            $this->markFailed($generation, $e->errorCode ?? 'api_error', $e->getMessage());
        }
    }

    protected function handleTransientError(Generation $generation, PixelforgeException $exception): void
    {
        $attempt = $generation->retry_count + 1;

        if ($attempt > self::MAX_TRANSIENT_RETRIES) {
            $this->markFailed(
                $generation,
                $exception->errorCode ?? 'transient_error',
                $exception->getMessage()
            );

            return;
        }

        $generation->retry_count = $attempt;
        $generation->save();

        $baseWait = max(1, $exception->retryAfter ?? 2);
        $delaySeconds = $baseWait * (2 ** ($attempt - 1));

        $this->release($delaySeconds);
    }

    protected function markFailed(Generation $generation, string $errorCode, string $errorMessage): void
    {
        DB::transaction(function () use ($generation, $errorCode, $errorMessage): void {
            $locked = Generation::query()->lockForUpdate()->find($generation->id);

            if ($locked === null || $locked->isFinal()) {
                return;
            }

            $locked->status = Generation::STATUS_FAILED;
            $locked->error_code = $errorCode;
            $locked->error_message = $errorMessage;
            $locked->provider_cost = '0.000000000';
            $locked->charged_cost = '0.000000000000';
            $locked->finished_at = now();
            $locked->save();
        });
    }
}
