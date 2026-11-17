<?php

use App\Models\CreditLedger;
use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;

beforeEach(function () {
    config([
        'pixelforge.webhook_secret' => 'whsec_test_signing_secret_123',
    ]);
});

/**
 * Helper to post a signed raw JSON webhook payload.
 *
 * @param  array<string, mixed>  $payload
 */
function postSignedWebhook(
    $test,
    array $payload,
    ?int $timestamp = null,
    ?string $overrideSignature = null,
) {
    $rawBody = json_encode($payload, JSON_THROW_ON_ERROR);
    $ts = $timestamp ?? time();
    $secret = (string) config('pixelforge.webhook_secret');
    $sig = $overrideSignature ?? hash_hmac('sha256', "{$ts}.{$rawBody}", $secret);

    return $test->call(
        'POST',
        '/webhooks/pixelforge',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_EVENT_ID' => (string) ($payload['id'] ?? ''),
            'HTTP_PIXELFORGE_EVENT_TYPE' => (string) ($payload['type'] ?? ''),
            'HTTP_PIXELFORGE_DELIVERY_ATTEMPT' => '1',
            'HTTP_PIXELFORGE_SIGNATURE' => "t={$ts},v1={$sig}",
        ],
        $rawBody,
    );
}

it('rejects a webhook with a bad signature with status 400', function () {
    $payload = [
        'id' => 'evt_bad_sig',
        'object' => 'event',
        'type' => 'ping',
        'created_at' => '2026-11-17T12:00:00Z',
        'data' => ['object' => []],
    ];

    $response = postSignedWebhook($this, $payload, time(), 'deadbeef_invalid_signature');

    $response->assertStatus(400);
    expect(WebhookEvent::query()->count())->toBe(0);
});

it('rejects a webhook with an old or future timestamp more than 300 seconds off with status 400', function () {
    $payload = [
        'id' => 'evt_old_timestamp',
        'object' => 'event',
        'type' => 'ping',
        'created_at' => '2026-11-17T12:00:00Z',
        'data' => ['object' => []],
    ];

    $oldResponse = postSignedWebhook($this, $payload, time() - 301);
    $oldResponse->assertStatus(400);

    $futureResponse = postSignedWebhook($this, $payload, time() + 301);
    $futureResponse->assertStatus(400);

    expect(WebhookEvent::query()->count())->toBe(0);
});

it('processes a succeeded event once and records only one charge when the same event is sent twice', function () {
    $user = User::factory()->create();
    $generation = Generation::query()->create([
        'user_id' => $user->id,
        'prompt' => 'A lighthouse on a cliff at dawn',
        'size' => '1536x1024',
        'quality' => 'high',
        'status' => Generation::STATUS_PROCESSING,
        'provider_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K',
        'estimated_cost' => '0.400000000000',
    ]);

    $payload = [
        'id' => 'evt_dup_test_1',
        'object' => 'event',
        'type' => 'generation.succeeded',
        'created_at' => '2026-11-04T14:03:29Z',
        'data' => [
            'object' => [
                'id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K',
                'object' => 'generation',
                'status' => 'succeeded',
                'metadata' => ['generation_id' => (string) $generation->id],
                'image_url' => 'https://pixelforge.promptedit.dev/images/gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K.png',
                'image_url_expires_at' => '2026-11-05T14:03:29Z',
                'usage' => [
                    'input_text_tokens' => 12,
                    'input_image_tokens' => 765,
                    'output_tokens' => 6450,
                    'reasoning_tokens' => 3184,
                    'total_tokens' => 10411,
                ],
                'rates' => [
                    'input_text_tokens' => '5.00',
                    'input_image_tokens' => '10.00',
                    'output_tokens' => '40.00',
                    'reasoning_tokens' => '12.50',
                ],
                'completed_at' => '2026-11-04T14:03:29Z',
            ],
        ],
    ];

    $first = postSignedWebhook($this, $payload);
    $second = postSignedWebhook($this, $payload);

    $first->assertStatus(204);
    $second->assertStatus(204);

    $generation->refresh();

    expect($generation->status)->toBe(Generation::STATUS_SUCCEEDED)
        ->and($generation->provider_cost)->toBe('0.305510000')
        ->and($generation->charged_cost)->toBe('0.381887500000')
        ->and($generation->image_url)->toBe('https://pixelforge.promptedit.dev/images/gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K.png')
        ->and(WebhookEvent::query()->count())->toBe(1)
        ->and(CreditLedger::query()->where('generation_id', $generation->id)->count())->toBe(1);

    $charge = CreditLedger::query()->where('generation_id', $generation->id)->first();
    expect($charge)->not->toBeNull()
        ->and($charge->type)->toBe(CreditLedger::TYPE_CHARGE)
        ->and($charge->amount)->toBe('0.381887500000');
});

it('ignores a late processing event arriving after succeeded', function () {
    $user = User::factory()->create();
    $generation = Generation::query()->create([
        'user_id' => $user->id,
        'prompt' => 'Already succeeded',
        'size' => '1024x1024',
        'quality' => 'medium',
        'status' => Generation::STATUS_SUCCEEDED,
        'provider_id' => 'gen_finished_1',
        'estimated_cost' => '0.070000000000',
        'provider_cost' => '0.050000000',
        'charged_cost' => '0.062500000000',
        'image_url' => 'https://pixelforge.promptedit.dev/images/gen_finished_1.png',
        'finished_at' => now(),
    ]);

    $lateProcessingPayload = [
        'id' => 'evt_late_processing_1',
        'object' => 'event',
        'type' => 'generation.processing',
        'created_at' => '2026-11-04T14:03:13Z',
        'data' => [
            'object' => [
                'id' => 'gen_finished_1',
                'object' => 'generation',
                'status' => 'processing',
                'metadata' => ['generation_id' => (string) $generation->id],
                'started_at' => '2026-11-04T14:03:13Z',
            ],
        ],
    ];

    $response = postSignedWebhook($this, $lateProcessingPayload);
    $response->assertStatus(204);

    $generation->refresh();

    expect($generation->status)->toBe(Generation::STATUS_SUCCEEDED)
        ->and($generation->provider_cost)->toBe('0.050000000')
        ->and($generation->charged_cost)->toBe('0.062500000000');
});

it('marks generation failed with cost 0 and writes no charge row on generation.failed', function () {
    $user = User::factory()->create();
    $generation = Generation::query()->create([
        'user_id' => $user->id,
        'prompt' => '[fail] prompt',
        'size' => '1024x1024',
        'quality' => 'medium',
        'status' => Generation::STATUS_PROCESSING,
        'provider_id' => 'gen_failed_1',
        'estimated_cost' => '0.070000000000',
    ]);

    $failedPayload = [
        'id' => 'evt_failed_1',
        'object' => 'event',
        'type' => 'generation.failed',
        'created_at' => '2026-11-04T14:03:20Z',
        'data' => [
            'object' => [
                'id' => 'gen_failed_1',
                'object' => 'generation',
                'status' => 'failed',
                'metadata' => ['generation_id' => (string) $generation->id],
                'usage' => null,
                'error' => [
                    'code' => 'internal_error',
                    'message' => 'Something went wrong on our side while generating.',
                ],
                'completed_at' => '2026-11-04T14:03:20Z',
            ],
        ],
    ];

    $response = postSignedWebhook($this, $failedPayload);
    $response->assertStatus(204);

    $generation->refresh();

    expect($generation->status)->toBe(Generation::STATUS_FAILED)
        ->and($generation->error_code)->toBe('internal_error')
        ->and($generation->error_message)->toBe('Something went wrong on our side while generating.')
        ->and($generation->provider_cost)->toBe('0.000000000')
        ->and($generation->charged_cost)->toBe('0.000000000000')
        ->and(CreditLedger::query()->where('generation_id', $generation->id)->count())->toBe(0);
});

it('matches by metadata.generation_id and saves provider_id when webhook arrives before create call returns', function () {
    $user = User::factory()->create();
    $generation = Generation::query()->create([
        'user_id' => $user->id,
        'prompt' => 'Fast webhook before create response saved provider_id',
        'size' => '1024x1024',
        'quality' => 'low',
        'status' => Generation::STATUS_QUEUED,
        'provider_id' => null,
        'estimated_cost' => '0.020000000000',
    ]);

    $payload = [
        'id' => 'evt_early_webhook_1',
        'object' => 'event',
        'type' => 'generation.processing',
        'created_at' => '2026-11-04T14:03:12Z',
        'data' => [
            'object' => [
                'id' => 'gen_early_provider_999',
                'object' => 'generation',
                'status' => 'processing',
                'metadata' => [
                    'generation_id' => (string) $generation->id,
                ],
                'started_at' => '2026-11-04T14:03:12Z',
            ],
        ],
    ];

    $response = postSignedWebhook($this, $payload);
    $response->assertStatus(204);

    $generation->refresh();

    expect($generation->provider_id)->toBe('gen_early_provider_999')
        ->and($generation->status)->toBe(Generation::STATUS_PROCESSING);
});

it('returns 204 and logs when webhook is for an unknown generation', function () {
    $payload = [
        'id' => 'evt_unknown_gen_1',
        'object' => 'event',
        'type' => 'generation.succeeded',
        'created_at' => '2026-11-04T14:03:29Z',
        'data' => [
            'object' => [
                'id' => 'gen_does_not_exist',
                'object' => 'generation',
                'status' => 'succeeded',
                'metadata' => ['generation_id' => '999999'],
            ],
        ],
    ];

    $response = postSignedWebhook($this, $payload);
    $response->assertStatus(204);

    $event = WebhookEvent::query()->where('event_id', 'evt_unknown_gen_1')->first();
    expect($event)->not->toBeNull()
        ->and($event->processed_at)->not->toBeNull();
});

it('returns 204 for ping events', function () {
    $payload = [
        'id' => 'evt_ping_1',
        'object' => 'event',
        'type' => 'ping',
        'created_at' => '2026-11-04T14:03:00Z',
        'data' => ['object' => []],
    ];

    $response = postSignedWebhook($this, $payload);
    $response->assertStatus(204);
});
