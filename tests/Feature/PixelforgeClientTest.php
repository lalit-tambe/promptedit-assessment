<?php

use App\Services\Pixelforge\Exceptions\ConcurrencyLimitExceededException;
use App\Services\Pixelforge\Exceptions\ContentPolicyViolationException;
use App\Services\Pixelforge\Exceptions\DailyLimitExceededException;
use App\Services\Pixelforge\Exceptions\InvalidApiKeyException;
use App\Services\Pixelforge\Exceptions\OverloadedException;
use App\Services\Pixelforge\Exceptions\RateLimitExceededException;
use App\Services\Pixelforge\Exceptions\ValidationFailedException;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'pixelforge.base_url' => 'https://pixelforge.promptedit.dev/v1',
        'pixelforge.api_key' => 'pf_test_secret_key',
    ]);
    Cache::flush();
});

it('creates a generation with Idempotency-Key and metadata.generation_id', function () {
    Http::fake([
        'https://pixelforge.promptedit.dev/v1/generations' => Http::response([
            'id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K',
            'object' => 'generation',
            'status' => 'queued',
            'prompt' => 'A lighthouse at dawn',
            'size' => '1536x1024',
            'quality' => 'high',
            'metadata' => ['generation_id' => '42'],
        ], 202),
    ]);

    $client = new PixelforgeClient;
    $result = $client->createGeneration([
        'generation_id' => 42,
        'prompt' => 'A lighthouse at dawn',
        'size' => '1536x1024',
        'quality' => 'high',
    ], '6f1c2a9e-3b7d-4e0a-9c55-2d8f1b4e7a10');

    expect($result['id'])->toBe('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K')
        ->and($result['status'])->toBe('queued')
        ->and($result['metadata']['generation_id'])->toBe('42');

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://pixelforge.promptedit.dev/v1/generations'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer pf_test_secret_key')
            && $request->hasHeader('Idempotency-Key', '6f1c2a9e-3b7d-4e0a-9c55-2d8f1b4e7a10')
            && ($request['metadata']['generation_id'] ?? null) === '42'
            && ! isset($request['generation_id']);
    });
});

it('fetches a generation by provider id', function () {
    Http::fake([
        'https://pixelforge.promptedit.dev/v1/generations/gen_123' => Http::response([
            'id' => 'gen_123',
            'object' => 'generation',
            'status' => 'succeeded',
        ], 200),
    ]);

    $client = new PixelforgeClient;
    $result = $client->getGeneration('gen_123');

    expect($result['id'])->toBe('gen_123')
        ->and($result['status'])->toBe('succeeded');
});

it('fetches pricing and caches it for 10 minutes', function () {
    Http::fake([
        'https://pixelforge.promptedit.dev/v1/pricing' => Http::response([
            'object' => 'pricing',
            'currency' => 'usd',
            'unit' => 'per_1m_tokens',
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
        ], 200),
    ]);

    $client = new PixelforgeClient;
    $first = $client->getPricing();
    $second = $client->getPricing();

    expect($first['rates']['output_tokens'])->toBe('40.00')
        ->and($second)->toEqual($first);

    Http::assertSentCount(1);
});

it('throws InvalidApiKeyException on 401 invalid_api_key', function () {
    Http::fake([
        'https://pixelforge.promptedit.dev/v1/generations' => Http::response([
            'error' => [
                'type' => 'authentication_error',
                'code' => 'invalid_api_key',
                'message' => 'The API key is missing, malformed or revoked.',
            ],
        ], 401),
    ]);

    $client = new PixelforgeClient;

    expect(fn () => $client->createGeneration(['prompt' => 'Test', 'generation_id' => 1], 'uuid-1'))
        ->toThrow(function (InvalidApiKeyException $e) {
            expect($e->statusCode)->toBe(401)
                ->and($e->errorCode)->toBe('invalid_api_key')
                ->and($e->getMessage())->toBe('The API key is missing, malformed or revoked.');
        });
});

it('throws ValidationFailedException on 422 validation_failed', function () {
    Http::fake([
        'https://pixelforge.promptedit.dev/v1/generations' => Http::response([
            'error' => [
                'type' => 'invalid_request_error',
                'code' => 'validation_failed',
                'message' => 'The selected size is invalid.',
                'param' => 'size',
                'errors' => [
                    'size' => ['The size must be one of: 1024x1024, 1536x1024, 1024x1536.'],
                ],
            ],
        ], 422),
    ]);

    $client = new PixelforgeClient;

    expect(fn () => $client->createGeneration(['prompt' => 'Test', 'size' => 'bad', 'generation_id' => 1], 'uuid-2'))
        ->toThrow(function (ValidationFailedException $e) {
            expect($e->statusCode)->toBe(422)
                ->and($e->errorCode)->toBe('validation_failed')
                ->and($e->param)->toBe('size')
                ->and($e->errors)->toHaveKey('size')
                ->and($e->getMessage())->toBe('The selected size is invalid.');
        });
});

it('throws ContentPolicyViolationException on 400 content_policy_violation', function () {
    Http::fake([
        'https://pixelforge.promptedit.dev/v1/generations' => Http::response([
            'error' => [
                'type' => 'invalid_request_error',
                'code' => 'content_policy_violation',
                'message' => 'The prompt was rejected due to content policy.',
            ],
        ], 400),
    ]);

    $client = new PixelforgeClient;

    expect(fn () => $client->createGeneration(['prompt' => '[blocked]', 'generation_id' => 1], 'uuid-3'))
        ->toThrow(function (ContentPolicyViolationException $e) {
            expect($e->statusCode)->toBe(400)
                ->and($e->errorCode)->toBe('content_policy_violation')
                ->and($e->getMessage())->toBe('The prompt was rejected due to content policy.');
        });
});

it('throws RateLimitExceededException with Retry-After seconds on 429 rate_limit_exceeded', function () {
    Http::fake([
        'https://pixelforge.promptedit.dev/v1/generations' => Http::response([
            'error' => [
                'type' => 'rate_limit_error',
                'code' => 'rate_limit_exceeded',
                'message' => 'Too many requests this minute.',
            ],
        ], 429, ['Retry-After' => '17']),
    ]);

    $client = new PixelforgeClient;

    expect(fn () => $client->createGeneration(['prompt' => 'Test', 'generation_id' => 1], 'uuid-4'))
        ->toThrow(function (RateLimitExceededException $e) {
            expect($e->statusCode)->toBe(429)
                ->and($e->errorCode)->toBe('rate_limit_exceeded')
                ->and($e->retryAfter)->toBe(17)
                ->and($e->getRetryAfter())->toBe(17);
        });
});

it('throws ConcurrencyLimitExceededException with Retry-After seconds on 429 concurrency_limit_exceeded', function () {
    Http::fake([
        'https://pixelforge.promptedit.dev/v1/generations' => Http::response([
            'error' => [
                'type' => 'rate_limit_error',
                'code' => 'concurrency_limit_exceeded',
                'message' => 'Too many generations queued or processing.',
            ],
        ], 429, ['Retry-After' => '5']),
    ]);

    $client = new PixelforgeClient;

    expect(fn () => $client->createGeneration(['prompt' => 'Test', 'generation_id' => 1], 'uuid-5'))
        ->toThrow(function (ConcurrencyLimitExceededException $e) {
            expect($e->statusCode)->toBe(429)
                ->and($e->errorCode)->toBe('concurrency_limit_exceeded')
                ->and($e->retryAfter)->toBe(5)
                ->and($e->getRetryAfter())->toBe(5);
        });
});

it('throws DailyLimitExceededException on 429 daily_limit_exceeded', function () {
    Http::fake([
        'https://pixelforge.promptedit.dev/v1/generations' => Http::response([
            'error' => [
                'type' => 'rate_limit_error',
                'code' => 'daily_limit_exceeded',
                'message' => 'The daily generation quota (UTC) is used up.',
            ],
        ], 429),
    ]);

    $client = new PixelforgeClient;

    expect(fn () => $client->createGeneration(['prompt' => 'Test', 'generation_id' => 1], 'uuid-6'))
        ->toThrow(function (DailyLimitExceededException $e) {
            expect($e->statusCode)->toBe(429)
                ->and($e->errorCode)->toBe('daily_limit_exceeded')
                ->and($e->getMessage())->toBe('The daily generation quota (UTC) is used up.');
        });
});

it('throws OverloadedException with Retry-After seconds on 503 overloaded', function () {
    Http::fake([
        'https://pixelforge.promptedit.dev/v1/generations' => Http::response([
            'error' => [
                'type' => 'api_error',
                'code' => 'overloaded',
                'message' => 'Pixelforge is temporarily overloaded.',
            ],
        ], 503, ['Retry-After' => '2']),
    ]);

    $client = new PixelforgeClient;

    expect(fn () => $client->createGeneration(['prompt' => '[overloaded]', 'generation_id' => 1], 'uuid-7'))
        ->toThrow(function (OverloadedException $e) {
            expect($e->statusCode)->toBe(503)
                ->and($e->errorCode)->toBe('overloaded')
                ->and($e->retryAfter)->toBe(2)
                ->and($e->getRetryAfter())->toBe(2);
        });
});
