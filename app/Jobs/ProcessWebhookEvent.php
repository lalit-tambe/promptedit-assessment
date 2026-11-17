<?php

namespace App\Jobs;

use App\Models\CreditLedger;
use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Services\Pixelforge\PricingCalculator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessWebhookEvent implements ShouldQueue
{
    use Queueable;

    public readonly int $webhookEventId;

    public function __construct(WebhookEvent|int $webhookEvent)
    {
        $this->webhookEventId = $webhookEvent instanceof WebhookEvent ? $webhookEvent->id : $webhookEvent;
    }

    public function handle(PricingCalculator $calculator): void
    {
        $webhookEvent = WebhookEvent::query()->find($this->webhookEventId);

        if ($webhookEvent === null || $webhookEvent->processed_at !== null) {
            return;
        }

        if ($webhookEvent->type === 'ping') {
            $webhookEvent->processed_at = now();
            $webhookEvent->save();

            return;
        }

        $payload = $webhookEvent->payload;
        /** @var array<string, mixed> $object */
        $object = is_array($payload['data']['object'] ?? null) ? $payload['data']['object'] : [];

        $generation = $this->findGeneration($object, $webhookEvent->generation_provider_id);

        if ($generation === null) {
            Log::warning('Received Pixelforge webhook for unknown generation.', [
                'event_id' => $webhookEvent->event_id,
                'type' => $webhookEvent->type,
                'provider_id' => $object['id'] ?? $webhookEvent->generation_provider_id,
                'metadata' => $object['metadata'] ?? null,
            ]);

            $webhookEvent->processed_at = now();
            $webhookEvent->save();

            return;
        }

        DB::transaction(function () use ($generation, $object, $webhookEvent, $calculator): void {
            $locked = Generation::query()->lockForUpdate()->find($generation->id);

            if ($locked === null) {
                return;
            }

            self::applyGenerationUpdate($locked, $object, $calculator, $webhookEvent->type);

            $webhookEvent->processed_at = now();
            $webhookEvent->save();
        });
    }

    /**
     * Apply status, usage, and billing updates from a Pixelforge generation object inside a locked transaction.
     *
     * @param  array<string, mixed>  $object
     */
    public static function applyGenerationUpdate(
        Generation $locked,
        array $object,
        PricingCalculator $calculator,
        ?string $eventType = null,
    ): void {
        $providerId = is_string($object['id'] ?? null) ? $object['id'] : null;

        if ($locked->provider_id === null && $providerId !== null && $providerId !== '') {
            $locked->provider_id = $providerId;
        }

        if (isset($object['rates']) && is_array($object['rates'])) {
            /** @var array<string, string> $rates */
            $rates = $object['rates'];
            $locked->rates = $rates;
        }

        // Never move a generation backwards; succeeded and failed are final
        if ($locked->isFinal()) {
            $locked->save();

            return;
        }

        $targetStatus = match ($eventType) {
            'generation.processing' => Generation::STATUS_PROCESSING,
            'generation.succeeded' => Generation::STATUS_SUCCEEDED,
            'generation.failed' => Generation::STATUS_FAILED,
            default => is_string($object['status'] ?? null) ? $object['status'] : $locked->status,
        };

        if ($targetStatus === Generation::STATUS_PROCESSING) {
            if ($locked->status === Generation::STATUS_QUEUED) {
                $locked->status = Generation::STATUS_PROCESSING;
                $locked->started_at = isset($object['started_at']) && is_string($object['started_at'])
                    ? Carbon::parse($object['started_at'])
                    : ($locked->started_at ?? now());
            }

            $locked->save();

            return;
        }

        if ($targetStatus === Generation::STATUS_SUCCEEDED) {
            /** @var array<string, int|string> $usage */
            $usage = is_array($object['usage'] ?? null) ? $object['usage'] : [];
            /** @var array<string, string>|null $rates */
            $rates = is_array($object['rates'] ?? null) ? $object['rates'] : $locked->rates;

            $providerCost = $calculator->providerCost($usage, $rates);
            $userPrice = $calculator->userPrice($providerCost);

            $textTokens = (int) ($usage['input_text_tokens'] ?? 0);
            $imageTokens = (int) ($usage['input_image_tokens'] ?? 0);
            $outputTokens = (int) ($usage['output_tokens'] ?? 0);
            $reasoningTokens = (int) ($usage['reasoning_tokens'] ?? 0);
            $totalTokens = isset($usage['total_tokens'])
                ? (int) $usage['total_tokens']
                : ($textTokens + $imageTokens + $outputTokens + $reasoningTokens);

            $locked->status = Generation::STATUS_SUCCEEDED;
            $locked->input_text_tokens = $textTokens;
            $locked->input_image_tokens = $imageTokens;
            $locked->output_tokens = $outputTokens;
            $locked->reasoning_tokens = $reasoningTokens;
            $locked->total_tokens = $totalTokens;
            $locked->rates = $rates;
            $locked->provider_cost = $providerCost;
            $locked->charged_cost = $userPrice;
            $locked->image_url = is_string($object['image_url'] ?? null) ? $object['image_url'] : $locked->image_url;
            $locked->image_url_expires_at = isset($object['image_url_expires_at']) && is_string($object['image_url_expires_at'])
                ? Carbon::parse($object['image_url_expires_at'])
                : $locked->image_url_expires_at;
            $locked->started_at = isset($object['started_at']) && is_string($object['started_at'])
                ? Carbon::parse($object['started_at'])
                : ($locked->started_at ?? now());
            $locked->finished_at = isset($object['completed_at']) && is_string($object['completed_at'])
                ? Carbon::parse($object['completed_at'])
                : now();
            $locked->error_code = null;
            $locked->error_message = null;
            $locked->save();

            CreditLedger::query()->firstOrCreate(
                [
                    'generation_id' => $locked->id,
                    'type' => CreditLedger::TYPE_CHARGE,
                ],
                [
                    'user_id' => $locked->user_id,
                    'amount' => $userPrice,
                ]
            );

            return;
        }

        if ($targetStatus === Generation::STATUS_FAILED) {
            /** @var array<string, mixed> $error */
            $error = is_array($object['error'] ?? null) ? $object['error'] : [];

            $locked->status = Generation::STATUS_FAILED;
            $locked->error_code = is_string($error['code'] ?? null) ? $error['code'] : 'generation_failed';
            $locked->error_message = is_string($error['message'] ?? null) ? $error['message'] : 'Generation failed.';
            $locked->provider_cost = '0.000000000';
            $locked->charged_cost = '0.000000000000';
            $locked->started_at = isset($object['started_at']) && is_string($object['started_at'])
                ? Carbon::parse($object['started_at'])
                : $locked->started_at;
            $locked->finished_at = isset($object['completed_at']) && is_string($object['completed_at'])
                ? Carbon::parse($object['completed_at'])
                : now();
            $locked->save();
        }
    }

    /**
     * @param  array<string, mixed>  $object
     */
    protected function findGeneration(array $object, ?string $fallbackProviderId): ?Generation
    {
        /** @var array<string, mixed> $metadata */
        $metadata = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];

        $metadataGenId = isset($metadata['generation_id']) ? (string) $metadata['generation_id'] : null;

        if ($metadataGenId !== null && $metadataGenId !== '') {
            if (ctype_digit($metadataGenId)) {
                $found = Generation::query()->find((int) $metadataGenId);
                if ($found !== null) {
                    return $found;
                }
            }

            $foundByUuid = Generation::query()->where('idempotency_key', $metadataGenId)->first();
            if ($foundByUuid !== null) {
                return $foundByUuid;
            }
        }

        $metadataIdempotencyKey = isset($metadata['idempotency_key']) ? (string) $metadata['idempotency_key'] : null;

        if ($metadataIdempotencyKey !== null && $metadataIdempotencyKey !== '') {
            $foundByUuid = Generation::query()->where('idempotency_key', $metadataIdempotencyKey)->first();
            if ($foundByUuid !== null) {
                return $foundByUuid;
            }
        }

        $providerId = is_string($object['id'] ?? null) ? $object['id'] : $fallbackProviderId;

        if ($providerId !== null && $providerId !== '') {
            return Generation::query()->where('provider_id', $providerId)->first();
        }

        return null;
    }
}
