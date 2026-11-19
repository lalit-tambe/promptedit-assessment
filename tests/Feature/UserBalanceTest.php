<?php

use App\Jobs\ProcessWebhookEvent;
use App\Models\CreditLedger;
use App\Models\Generation;
use App\Models\User;
use App\Services\CreditBalanceService;
use App\Services\Pixelforge\PricingCalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config([
        'pixelforge.base_url' => 'https://pixelforge.promptedit.dev/v1',
        'pixelforge.api_key' => 'pf_test_key',
        'pixelforge.credit_purchase_amount' => '5.000000000000',
    ]);
});

it('calculates exact decimal balance from purchase and charge ledger rows without float drift', function () {
    $user = User::factory()->create();
    $service = app(CreditBalanceService::class);

    expect($service->balanceFor($user))->toBe('0.000000000000');

    CreditLedger::query()->create([
        'user_id' => $user->id,
        'generation_id' => null,
        'type' => CreditLedger::TYPE_PURCHASE,
        'amount' => '1.000000000000',
    ]);

    $gen1 = Generation::query()->create([
        'user_id' => $user->id,
        'prompt' => 'First image',
        'size' => '1536x1024',
        'quality' => 'high',
        'status' => Generation::STATUS_SUCCEEDED,
        'estimated_cost' => '0.380000000000',
        'charged_cost' => '0.381887500000',
    ]);

    $gen2 = Generation::query()->create([
        'user_id' => $user->id,
        'prompt' => 'Second image',
        'size' => '1024x1024',
        'quality' => 'medium',
        'status' => Generation::STATUS_SUCCEEDED,
        'estimated_cost' => '0.079000000000',
        'charged_cost' => '0.079731000000',
    ]);

    CreditLedger::query()->create([
        'user_id' => $user->id,
        'generation_id' => $gen1->id,
        'type' => CreditLedger::TYPE_CHARGE,
        'amount' => '0.381887500000',
    ]);

    CreditLedger::query()->create([
        'user_id' => $user->id,
        'generation_id' => $gen2->id,
        'type' => CreditLedger::TYPE_CHARGE,
        'amount' => '0.079731000000',
    ]);

    // 1.000000000000 - 0.381887500000 - 0.079731000000 = 0.538381500000 exact
    expect($service->balanceFor($user))->toBe('0.538381500000');
});

it('shares the user balance in Inertia props for navigation across pages', function () {
    $user = User::factory()->create();

    CreditLedger::query()->create([
        'user_id' => $user->id,
        'generation_id' => null,
        'type' => CreditLedger::TYPE_PURCHASE,
        'amount' => '5.000000000000',
    ]);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('auth.balance', '5.000000000000')
        );

    $this->actingAs($user)
        ->get('/generations')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('auth.balance', '5.000000000000')
        );
});

it('allows authenticated users to buy a fixed amount of credits multiple times', function () {
    $this->post('/credits/purchase')->assertRedirect('/login');

    $user = User::factory()->create();
    $service = app(CreditBalanceService::class);

    $this->actingAs($user)
        ->from('/generate')
        ->post('/credits/purchase')
        ->assertRedirect('/generate')
        ->assertSessionHas('status', 'Added $5.00 in credits to your balance.');

    expect($service->balanceFor($user))->toBe('5.000000000000');

    // Buying a second time adds a second purchase row (generation_id = null)
    $this->actingAs($user)
        ->from('/generate')
        ->post('/credits/purchase')
        ->assertRedirect('/generate');

    expect($service->balanceFor($user))->toBe('10.000000000000')
        ->and(CreditLedger::query()->where('user_id', $user->id)->where('type', CreditLedger::TYPE_PURCHASE)->count())->toBe(2);
});

it('blocks creating a generation when the user balance is lower than the estimated cost', function () {
    Queue::fake();

    $user = User::factory()->create();

    // Balance is $0.01, but estimated cost for this prompt is $0.027531250000
    CreditLedger::query()->create([
        'user_id' => $user->id,
        'generation_id' => null,
        'type' => CreditLedger::TYPE_PURCHASE,
        'amount' => '0.010000000000',
    ]);

    $response = $this->actingAs($user)
        ->from('/generate')
        ->post('/generations', [
            'prompt' => str_repeat('a', 40),
            'size' => '1024x1024',
            'quality' => 'low',
            'reference_images' => ['https://example.com/ref1.png'],
        ]);

    $response->assertRedirect('/generate')
        ->assertSessionHasErrors('balance');

    expect(Generation::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('prevents concurrent or queued generations from overspending the balance below zero', function () {
    Queue::fake();

    $user = User::factory()->create();

    // Enough for ONE $0.027531250000 generation, NOT two ($0.055062500000)
    CreditLedger::query()->create([
        'user_id' => $user->id,
        'generation_id' => null,
        'type' => CreditLedger::TYPE_PURCHASE,
        'amount' => '0.050000000000',
    ]);

    $payload = [
        'prompt' => str_repeat('a', 40),
        'size' => '1024x1024',
        'quality' => 'low',
        'reference_images' => ['https://example.com/ref1.png'],
    ];

    // First request succeeds and queues generation #1
    $this->actingAs($user)
        ->post('/generations', $payload)
        ->assertRedirect('/generations');

    expect(Generation::query()->count())->toBe(1);

    // Second request while #1 is still queued must be blocked because available balance is $0.02246875 < $0.02753125
    $this->actingAs($user)
        ->from('/generate')
        ->post('/generations', $payload)
        ->assertRedirect('/generate')
        ->assertSessionHasErrors('balance');

    expect(Generation::query()->count())->toBe(1);

    // If generation #1 fails (cost 0), its reserved estimate is released so a new generation is allowed
    $generation = Generation::query()->firstOrFail();
    DB::transaction(function () use ($generation): void {
        ProcessWebhookEvent::applyGenerationUpdate(
            $generation,
            [
                'id' => 'gen_failed_1',
                'status' => 'failed',
                'error' => ['code' => 'generation_failed', 'message' => 'Failed'],
            ],
            app(PricingCalculator::class),
            'generation.failed',
        );
    });

    expect(app(CreditBalanceService::class)->balanceFor($user))->toBe('0.050000000000');

    $this->actingAs($user)
        ->post('/generations', $payload)
        ->assertRedirect('/generations');

    expect(Generation::query()->count())->toBe(2);
});

it('subtracts the exact real charge from the user balance when a generation succeeds', function () {
    $user = User::factory()->create();
    $service = app(CreditBalanceService::class);

    $service->purchaseCredits($user, '5.000000000000');

    $generation = $service->createGeneration($user, [
        'prompt' => 'Exact charge test',
        'size' => '1536x1024',
        'quality' => 'high',
        'reference_urls' => ['https://example.com/ref.png'],
        'estimated_cost' => '0.380000000000',
    ]);

    DB::transaction(function () use ($generation): void {
        ProcessWebhookEvent::applyGenerationUpdate(
            $generation,
            [
                'id' => 'gen_succeeded_balance',
                'status' => 'succeeded',
                'usage' => [
                    'input_text_tokens' => 12,
                    'input_image_tokens' => 765,
                    'output_tokens' => 6450,
                    'reasoning_tokens' => 3184,
                ],
                'rates' => [
                    'input_text_tokens' => '5.00',
                    'input_image_tokens' => '10.00',
                    'output_tokens' => '40.00',
                    'reasoning_tokens' => '12.50',
                ],
            ],
            app(PricingCalculator::class),
            'generation.succeeded',
        );
    });

    // 5.000000000000 - 0.381887500000 = 4.618112500000
    expect($service->balanceFor($user))->toBe('4.618112500000');
});
