<?php

use App\Jobs\SubmitGenerationJob;
use App\Models\Generation;
use App\Models\User;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config([
        'pixelforge.base_url' => 'https://pixelforge.promptedit.dev/v1',
        'pixelforge.api_key' => 'pf_test_key',
    ]);
});

it('renders the Generate page with pricing props and initial estimates from the server', function () {
    Http::fake([
        'https://pixelforge.promptedit.dev/v1/pricing' => Http::response([
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
        ], 200),
    ]);

    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/generate');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Generate')
            ->has('rates')
            ->has('sizes', 3)
            ->has('qualities', 3)
            ->where('markup', '1.25')
            ->where('initialEstimate.user_price', '0.072187500000')
            ->where('initialMaxEstimate.user_price', '0.086250000000')
        );
});

it('calculates live estimate and max_estimate via POST /generations/estimate using PricingCalculator', function () {
    Http::fake([
        'https://pixelforge.promptedit.dev/v1/pricing' => Http::response([
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
        ], 200),
    ]);

    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/generations/estimate', [
        'prompt' => str_repeat('a', 400),
        'size' => '1536x1024',
        'quality' => 'high',
        'reference_images' => [
            'https://example.com/1.png',
            'https://example.com/2.png',
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('max_estimate.provider_cost', '0.373875000')
        ->assertJsonPath('max_estimate.user_price', '0.467343750000');
});

it('validates generation creation fields', function () {
    $user = User::factory()->create();

    // Missing prompt and invalid size/quality
    $this->actingAs($user)
        ->post('/generations', [
            'prompt' => '',
            'size' => '512x512',
            'quality' => 'ultra',
            'reference_images' => ['ftp://bad-scheme.com/img.png'],
        ])
        ->assertSessionHasErrors(['prompt', 'size', 'quality', 'reference_images.0']);

    // Prompt over 4000 characters and more than 4 reference images
    $this->actingAs($user)
        ->post('/generations', [
            'prompt' => str_repeat('a', 4001),
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [
                'https://example.com/1.png',
                'https://example.com/2.png',
                'https://example.com/3.png',
                'https://example.com/4.png',
                'https://example.com/5.png',
            ],
        ])
        ->assertSessionHasErrors(['prompt', 'reference_images']);
});

it('saves a queued generation with estimated cost and dispatches SubmitGenerationJob', function () {
    Queue::fake();

    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/generations', [
        'prompt' => str_repeat('a', 40), // 10 text tokens
        'size' => '1024x1024',
        'quality' => 'low',
        'reference_images' => ['https://example.com/ref1.png'],
    ]);

    $response->assertRedirect('/generations');

    $generation = Generation::query()->first();

    expect($generation)->not->toBeNull()
        ->and($generation->user_id)->toBe($user->id)
        ->and($generation->status)->toBe(Generation::STATUS_QUEUED)
        ->and($generation->size)->toBe('1024x1024')
        ->and($generation->quality)->toBe('low')
        ->and($generation->reference_urls)->toBe(['https://example.com/ref1.png'])
        // text: 10*5=50, image: 765*10=7650, output: 280*40=11200, reasoning: 250*12.5=3125 -> 22025/1M = 0.022025000 * 1.25 = 0.027531250000
        ->and($generation->estimated_cost)->toBe('0.027531250000');

    Queue::assertPushed(SubmitGenerationJob::class, fn (SubmitGenerationJob $job): bool => $job->generationId === $generation->id);
});

it('submits generation to Pixelforge with UUID Idempotency-Key and metadata and saves provider_id', function () {
    Http::fake([
        'https://pixelforge.promptedit.dev/v1/generations' => Http::response([
            'id' => 'gen_abc123',
            'object' => 'generation',
            'status' => 'queued',
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
        ], 202),
    ]);

    $user = User::factory()->create();
    $generation = Generation::query()->create([
        'user_id' => $user->id,
        'prompt' => 'A mountain lake',
        'size' => '1536x1024',
        'quality' => 'high',
        'reference_urls' => ['https://example.com/style.png'],
        'status' => Generation::STATUS_QUEUED,
        'estimated_cost' => '0.400000000000',
    ]);

    $job = new SubmitGenerationJob($generation);
    $job->handle(new PixelforgeClient);

    $generation->refresh();

    expect($generation->provider_id)->toBe('gen_abc123')
        ->and($generation->status)->toBe(Generation::STATUS_QUEUED)
        ->and($generation->rates)->toHaveKey('output_tokens', '40.00');

    Http::assertSent(function (Request $request) use ($generation): bool {
        return $request->hasHeader('Idempotency-Key', $generation->idempotency_key)
            && ($request['metadata']['generation_id'] ?? null) === (string) $generation->id
            && ($request['metadata']['idempotency_key'] ?? null) === $generation->idempotency_key;
    });
});

it('does not re-send a generation that already has a Pixelforge provider_id', function () {
    Http::fake();

    $user = User::factory()->create();
    $generation = Generation::query()->create([
        'user_id' => $user->id,
        'prompt' => 'Already sent',
        'size' => '1024x1024',
        'quality' => 'medium',
        'status' => Generation::STATUS_QUEUED,
        'provider_id' => 'gen_already_exists',
        'estimated_cost' => '0.050000000000',
    ]);

    $job = new SubmitGenerationJob($generation);
    $job->handle(new PixelforgeClient);

    Http::assertNothingSent();
});

it('keeps extra generations queued when 3 unfinished generations are already at Pixelforge', function () {
    Http::fake();

    $user = User::factory()->create();

    for ($i = 1; $i <= 3; $i++) {
        Generation::query()->create([
            'user_id' => $user->id,
            'prompt' => "In-flight {$i}",
            'size' => '1024x1024',
            'quality' => 'medium',
            'status' => $i === 1 ? Generation::STATUS_QUEUED : Generation::STATUS_PROCESSING,
            'provider_id' => "gen_inflight_{$i}",
            'estimated_cost' => '0.050000000000',
        ]);
    }

    $fourth = Generation::query()->create([
        'user_id' => $user->id,
        'prompt' => 'Fourth job waiting',
        'size' => '1024x1024',
        'quality' => 'medium',
        'status' => Generation::STATUS_QUEUED,
        'estimated_cost' => '0.050000000000',
    ]);

    $fakeQueueJob = Mockery::mock(Job::class);
    $fakeQueueJob->shouldReceive('release')->once()->with(SubmitGenerationJob::CONCURRENCY_RETRY_SECONDS);

    $job = new SubmitGenerationJob($fourth);
    $job->setJob($fakeQueueJob);
    $job->handle(new PixelforgeClient);

    $fourth->refresh();

    expect($fourth->status)->toBe(Generation::STATUS_QUEUED)
        ->and($fourth->provider_id)->toBeNull();

    Http::assertNothingSent();
});

it('releases and retries with growing waits on 429 rate_limit_exceeded and caps retries', function () {
    Http::fake([
        'https://pixelforge.promptedit.dev/v1/generations' => Http::response([
            'error' => [
                'code' => 'rate_limit_exceeded',
                'message' => 'Too many requests this minute.',
            ],
        ], 429, ['Retry-After' => '10']),
    ]);

    $user = User::factory()->create();
    $generation = Generation::query()->create([
        'user_id' => $user->id,
        'prompt' => 'Rate limited prompt',
        'size' => '1024x1024',
        'quality' => 'medium',
        'status' => Generation::STATUS_QUEUED,
        'estimated_cost' => '0.050000000000',
    ]);

    // Attempt 1: releases for 10s (10 * 2^0)
    $queueJob1 = Mockery::mock(Job::class);
    $queueJob1->shouldReceive('release')->once()->with(10);
    $job1 = new SubmitGenerationJob($generation);
    $job1->setJob($queueJob1);
    $job1->handle(new PixelforgeClient);

    expect($generation->refresh()->status)->toBe(Generation::STATUS_QUEUED)
        ->and($generation->retry_count)->toBe(1);

    // Attempt 2: releases for 20s (10 * 2^1)
    $queueJob2 = Mockery::mock(Job::class);
    $queueJob2->shouldReceive('release')->once()->with(20);
    $job2 = new SubmitGenerationJob($generation);
    $job2->setJob($queueJob2);
    $job2->handle(new PixelforgeClient);

    expect($generation->refresh()->retry_count)->toBe(2);

    // Attempt 3: releases for 40s (10 * 2^2)
    $queueJob3 = Mockery::mock(Job::class);
    $queueJob3->shouldReceive('release')->once()->with(40);
    $job3 = new SubmitGenerationJob($generation);
    $job3->setJob($queueJob3);
    $job3->handle(new PixelforgeClient);

    expect($generation->refresh()->retry_count)->toBe(3);

    // Attempt 4 (exceeds MAX_TRANSIENT_RETRIES = 3): marks failed with cost 0
    $queueJob4 = Mockery::mock(Job::class);
    $queueJob4->shouldNotReceive('release');
    $job4 = new SubmitGenerationJob($generation);
    $job4->setJob($queueJob4);
    $job4->handle(new PixelforgeClient);

    $generation->refresh();
    expect($generation->status)->toBe(Generation::STATUS_FAILED)
        ->and($generation->provider_cost)->toBe('0.000000000')
        ->and($generation->charged_cost)->toBe('0.000000000000');
});

it('releases and retries on 503 overloaded', function () {
    Http::fake([
        'https://pixelforge.promptedit.dev/v1/generations' => Http::response([
            'error' => [
                'code' => 'overloaded',
                'message' => 'Pixelforge is temporarily overloaded.',
            ],
        ], 503, ['Retry-After' => '2']),
    ]);

    $user = User::factory()->create();
    $generation = Generation::query()->create([
        'user_id' => $user->id,
        'prompt' => '[overloaded]',
        'size' => '1024x1024',
        'quality' => 'medium',
        'status' => Generation::STATUS_QUEUED,
        'estimated_cost' => '0.050000000000',
    ]);

    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('release')->once()->with(2);

    $job = new SubmitGenerationJob($generation);
    $job->setJob($queueJob);
    $job->handle(new PixelforgeClient);

    $generation->refresh();
    expect($generation->status)->toBe(Generation::STATUS_QUEUED)
        ->and($generation->retry_count)->toBe(1);
});

it('keeps generation queued and retries after Retry-After on 429 concurrency_limit_exceeded', function () {
    Http::fake([
        'https://pixelforge.promptedit.dev/v1/generations' => Http::response([
            'error' => [
                'code' => 'concurrency_limit_exceeded',
                'message' => 'Too many generations queued or processing.',
            ],
        ], 429, ['Retry-After' => '5']),
    ]);

    $user = User::factory()->create();
    $generation = Generation::query()->create([
        'user_id' => $user->id,
        'prompt' => 'Wait for slot',
        'size' => '1024x1024',
        'quality' => 'medium',
        'status' => Generation::STATUS_QUEUED,
        'estimated_cost' => '0.050000000000',
    ]);

    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('release')->once()->with(5);

    $job = new SubmitGenerationJob($generation);
    $job->setJob($queueJob);
    $job->handle(new PixelforgeClient);

    $generation->refresh();
    expect($generation->status)->toBe(Generation::STATUS_QUEUED)
        ->and($generation->retry_count)->toBe(0);
});

it('marks generation failed with cost 0 on daily limit, content policy, validation, and bad API key errors', function (int $status, string $code, string $apiMessage, string $expectedMessage) {
    Http::fake([
        'https://pixelforge.promptedit.dev/v1/generations' => Http::response([
            'error' => [
                'code' => $code,
                'message' => $apiMessage,
            ],
        ], $status),
    ]);

    $user = User::factory()->create();
    $generation = Generation::query()->create([
        'user_id' => $user->id,
        'prompt' => 'Test permanent failure',
        'size' => '1024x1024',
        'quality' => 'medium',
        'status' => Generation::STATUS_QUEUED,
        'estimated_cost' => '0.050000000000',
    ]);

    $job = new SubmitGenerationJob($generation);
    $job->handle(new PixelforgeClient);

    $generation->refresh();

    expect($generation->status)->toBe(Generation::STATUS_FAILED)
        ->and($generation->error_code)->toBe($code)
        ->and($generation->error_message)->toBe($expectedMessage)
        ->and($generation->provider_cost)->toBe('0.000000000')
        ->and($generation->charged_cost)->toBe('0.000000000000')
        ->and($generation->finished_at)->not->toBeNull();
})->with([
    'daily_limit_exceeded' => [429, 'daily_limit_exceeded', 'Quota used up.', 'Daily limit reached, try tomorrow.'],
    'content_policy_violation' => [400, 'content_policy_violation', 'Blocked prompt.', 'Prompt was rejected.'],
    'validation_failed' => [422, 'validation_failed', 'The selected size is invalid.', 'The selected size is invalid.'],
    'invalid_api_key' => [401, 'invalid_api_key', 'The API key is missing, malformed or revoked.', 'The API key is missing, malformed or revoked.'],
]);
