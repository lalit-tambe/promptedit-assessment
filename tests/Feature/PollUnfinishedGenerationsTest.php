<?php

use App\Jobs\PollUnfinishedGenerationsJob;
use App\Models\CreditLedger;
use App\Models\Generation;
use App\Models\User;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PricingCalculator;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'pixelforge.base_url' => 'https://pixelforge.promptedit.dev/v1',
        'pixelforge.api_key' => 'pf_test_key',
        'pixelforge.webhook_secret' => 'whsec_test_secret_123',
    ]);
});

it('finds a succeeded generation by polling when no webhook arrived and charges once', function () {
    Http::fake([
        'https://pixelforge.promptedit.dev/v1/generations/gen_polled_1' => Http::response([
            'id' => 'gen_polled_1',
            'object' => 'generation',
            'status' => 'succeeded',
            'image_url' => 'https://pixelforge.promptedit.dev/images/gen_polled_1.png',
            'image_url_expires_at' => '2026-11-18T14:03:29Z',
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
            'started_at' => '2026-11-17T14:03:13Z',
            'completed_at' => '2026-11-17T14:03:29Z',
        ], 200),
    ]);

    $user = User::factory()->create();

    // Stale unfinished generation (> 2 minutes old with provider_id)
    $staleGeneration = Generation::query()->create([
        'user_id' => $user->id,
        'prompt' => 'Missed webhook prompt',
        'size' => '1536x1024',
        'quality' => 'high',
        'status' => Generation::STATUS_PROCESSING,
        'provider_id' => 'gen_polled_1',
        'estimated_cost' => '0.400000000000',
    ]);
    Generation::query()->whereKey($staleGeneration->id)->update([
        'updated_at' => now()->subMinutes(3),
    ]);

    // Fresh unfinished generation (< 2 minutes old) - should NOT be polled
    Generation::query()->create([
        'user_id' => $user->id,
        'prompt' => 'Fresh generation',
        'size' => '1024x1024',
        'quality' => 'medium',
        'status' => Generation::STATUS_PROCESSING,
        'provider_id' => 'gen_fresh_2',
        'estimated_cost' => '0.080000000000',
    ]);

    $job = new PollUnfinishedGenerationsJob;
    $job->handle(new PixelforgeClient, new PricingCalculator);

    $staleGeneration->refresh();

    expect($staleGeneration->status)->toBe(Generation::STATUS_SUCCEEDED)
        ->and($staleGeneration->provider_cost)->toBe('0.305510000')
        ->and($staleGeneration->charged_cost)->toBe('0.381887500000')
        ->and($staleGeneration->image_url)->toBe('https://pixelforge.promptedit.dev/images/gen_polled_1.png')
        ->and(CreditLedger::query()->where('generation_id', $staleGeneration->id)->count())->toBe(1);

    Http::assertSentCount(1);
});

it('charges only once when polling completes a generation and a late webhook arrives afterwards', function () {
    $remoteGenerationObject = [
        'id' => 'gen_poll_then_webhook',
        'object' => 'generation',
        'status' => 'succeeded',
        'image_url' => 'https://pixelforge.promptedit.dev/images/gen_poll_then_webhook.png',
        'image_url_expires_at' => '2026-11-18T14:03:29Z',
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
        'started_at' => '2026-11-17T14:03:13Z',
        'completed_at' => '2026-11-17T14:03:29Z',
    ];

    Http::fake([
        'https://pixelforge.promptedit.dev/v1/generations/gen_poll_then_webhook' => Http::response($remoteGenerationObject, 200),
    ]);

    $user = User::factory()->create();
    $generation = Generation::query()->create([
        'user_id' => $user->id,
        'prompt' => 'Polled first, webhook later',
        'size' => '1536x1024',
        'quality' => 'high',
        'status' => Generation::STATUS_QUEUED,
        'provider_id' => 'gen_poll_then_webhook',
        'estimated_cost' => '0.400000000000',
    ]);
    Generation::query()->whereKey($generation->id)->update([
        'updated_at' => now()->subMinutes(5),
    ]);

    // Step 1: Polling finds the succeeded generation and charges once
    $pollJob = new PollUnfinishedGenerationsJob;
    $pollJob->handle(new PixelforgeClient, new PricingCalculator);

    expect($generation->refresh()->status)->toBe(Generation::STATUS_SUCCEEDED)
        ->and(CreditLedger::query()->where('generation_id', $generation->id)->count())->toBe(1);

    // Step 2: Late webhook for the same generation arrives afterwards
    $webhookPayload = [
        'id' => 'evt_late_succeeded_after_poll',
        'object' => 'event',
        'type' => 'generation.succeeded',
        'created_at' => '2026-11-17T14:03:29Z',
        'data' => [
            'object' => array_merge($remoteGenerationObject, [
                'metadata' => ['generation_id' => (string) $generation->id],
            ]),
        ],
    ];

    $rawBody = json_encode($webhookPayload, JSON_THROW_ON_ERROR);
    $ts = time();
    $sig = hash_hmac('sha256', "{$ts}.{$rawBody}", (string) config('pixelforge.webhook_secret'));

    $response = $this->call(
        'POST',
        '/webhooks/pixelforge',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_EVENT_ID' => 'evt_late_succeeded_after_poll',
            'HTTP_PIXELFORGE_EVENT_TYPE' => 'generation.succeeded',
            'HTTP_PIXELFORGE_SIGNATURE' => "t={$ts},v1={$sig}",
        ],
        $rawBody,
    );

    $response->assertStatus(204);

    $generation->refresh();
    expect($generation->status)->toBe(Generation::STATUS_SUCCEEDED)
        ->and($generation->provider_cost)->toBe('0.305510000')
        ->and($generation->charged_cost)->toBe('0.381887500000')
        ->and(CreditLedger::query()->where('generation_id', $generation->id)->count())->toBe(1);
});

it('schedules PollUnfinishedGenerationsJob to run every minute', function () {
    /** @var Schedule $schedule */
    $schedule = app(Schedule::class);

    $events = collect($schedule->events())->filter(
        fn ($event): bool => str_contains((string) $event->description, PollUnfinishedGenerationsJob::class)
    );

    expect($events)->not->toBeEmpty()
        ->and($events->first()->expression)->toBe('* * * * *');
});
