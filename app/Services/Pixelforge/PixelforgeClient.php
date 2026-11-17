<?php

namespace App\Services\Pixelforge;

use App\Services\Pixelforge\Exceptions\ConcurrencyLimitExceededException;
use App\Services\Pixelforge\Exceptions\ContentPolicyViolationException;
use App\Services\Pixelforge\Exceptions\DailyLimitExceededException;
use App\Services\Pixelforge\Exceptions\InvalidApiKeyException;
use App\Services\Pixelforge\Exceptions\OverloadedException;
use App\Services\Pixelforge\Exceptions\PixelforgeException;
use App\Services\Pixelforge\Exceptions\RateLimitExceededException;
use App\Services\Pixelforge\Exceptions\ValidationFailedException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class PixelforgeClient
{
    public const PRICING_CACHE_KEY = 'pixelforge.pricing';

    public const PRICING_CACHE_TTL_SECONDS = 600;

    /**
     * Send POST /generations with Idempotency-Key and metadata.generation_id.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    public function createGeneration(array $fields, string $idempotencyKey): array
    {
        $payload = $fields;

        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];

        if (isset($payload['generation_id'])) {
            $metadata['generation_id'] = (string) $payload['generation_id'];
            unset($payload['generation_id']);
        } elseif (isset($payload['id'])) {
            $metadata['generation_id'] = (string) $payload['id'];
            unset($payload['id']);
        } elseif (isset($metadata['generation_id'])) {
            $metadata['generation_id'] = (string) $metadata['generation_id'];
        }

        if ($metadata !== []) {
            $payload['metadata'] = array_map(static fn (mixed $value): string => (string) $value, $metadata);
        }

        if (array_key_exists('reference_urls', $payload) && ! array_key_exists('reference_images', $payload)) {
            if (! empty($payload['reference_urls'])) {
                $payload['reference_images'] = array_values((array) $payload['reference_urls']);
            }
            unset($payload['reference_urls']);
        }

        if (array_key_exists('reference_images', $payload) && empty($payload['reference_images'])) {
            unset($payload['reference_images']);
        }

        $response = $this->request()
            ->withHeaders([
                'Idempotency-Key' => $idempotencyKey,
            ])
            ->post('/generations', $payload);

        return $this->handleResponse($response);
    }

    /**
     * Fetch a single generation from GET /generations/{providerId}.
     *
     * @return array<string, mixed>
     */
    public function getGeneration(string $providerId): array
    {
        $response = $this->request()->get("/generations/{$providerId}");

        return $this->handleResponse($response);
    }

    /**
     * Fetch current token rates and rules from GET /pricing, cached for 10 minutes.
     *
     * @return array<string, mixed>
     */
    public function getPricing(): array
    {
        /** @var array<string, mixed> $pricing */
        $pricing = Cache::remember(
            self::PRICING_CACHE_KEY,
            self::PRICING_CACHE_TTL_SECONDS,
            function (): array {
                $response = Http::baseUrl($this->baseUrl())
                    ->acceptJson()
                    ->get('/pricing');

                return $this->handleResponse($response);
            }
        );

        return $pricing;
    }

    /**
     * Fetch daily usage report from GET /usage.
     *
     * @return array<string, mixed>
     */
    public function getUsage(?string $startDate = null, ?string $endDate = null): array
    {
        $query = array_filter([
            'start_date' => $startDate,
            'end_date' => $endDate,
        ], static fn (?string $value): bool => $value !== null && $value !== '');

        $response = $this->request()->get('/usage', $query);

        return $this->handleResponse($response);
    }

    protected function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->withToken($this->apiKey())
            ->acceptJson()
            ->asJson();
    }

    protected function baseUrl(): string
    {
        return rtrim((string) config('pixelforge.base_url', 'https://pixelforge.promptedit.dev/v1'), '/');
    }

    protected function apiKey(): string
    {
        return (string) config('pixelforge.api_key', '');
    }

    /**
     * @return array<string, mixed>
     */
    protected function handleResponse(Response $response): array
    {
        if ($response->successful()) {
            /** @var array<string, mixed> $data */
            $data = $response->json() ?? [];

            return $data;
        }

        $status = $response->status();
        $errorCode = is_string($response->json('error.code')) ? $response->json('error.code') : null;
        $message = is_string($response->json('error.message'))
            ? $response->json('error.message')
            : "Pixelforge API error ({$status})";

        $retryAfterHeader = $response->header('Retry-After');
        $retryAfter = $retryAfterHeader !== '' && is_numeric($retryAfterHeader)
            ? (int) $retryAfterHeader
            : null;

        match ($errorCode) {
            'invalid_api_key' => throw new InvalidApiKeyException($message, $status),
            'validation_failed' => throw new ValidationFailedException(
                $message,
                $status,
                is_array($response->json('error.errors')) ? $response->json('error.errors') : [],
                is_string($response->json('error.param')) ? $response->json('error.param') : null,
            ),
            'content_policy_violation' => throw new ContentPolicyViolationException($message, $status),
            'rate_limit_exceeded' => throw new RateLimitExceededException($message, $retryAfter ?? 60, $status),
            'concurrency_limit_exceeded' => throw new ConcurrencyLimitExceededException($message, $retryAfter ?? 5, $status),
            'daily_limit_exceeded' => throw new DailyLimitExceededException($message, $status),
            'overloaded' => throw new OverloadedException($message, $retryAfter ?? 2, $status),
            default => null,
        };

        match ($status) {
            400 => throw new ContentPolicyViolationException($message, $status),
            401 => throw new InvalidApiKeyException($message, $status),
            422 => throw new ValidationFailedException($message, $status),
            429 => throw new RateLimitExceededException($message, $retryAfter ?? 60, $status),
            503 => throw new OverloadedException($message, $retryAfter ?? 2, $status),
            default => throw new PixelforgeException($message, $status, $errorCode, $retryAfter),
        };
    }
}
