<?php

use App\Models\CreditLedger;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Carbon;

it('verifies provider cost times 1.25 and prints UTC daily totals in money:check', function () {
    $user = User::factory()->create();

    $gen1 = Generation::query()->create([
        'user_id' => $user->id,
        'prompt' => 'First succeeded generation',
        'size' => '1536x1024',
        'quality' => 'high',
        'status' => Generation::STATUS_SUCCEEDED,
        'provider_id' => 'gen_check_1',
        'estimated_cost' => '0.400000000000',
        'provider_cost' => '0.305510000',
        'charged_cost' => '0.381887500000',
        'finished_at' => Carbon::parse('2026-11-17T22:00:00Z'),
    ]);

    CreditLedger::query()->create([
        'user_id' => $user->id,
        'generation_id' => $gen1->id,
        'type' => CreditLedger::TYPE_CHARGE,
        'amount' => '0.381887500000',
    ]);

    $gen2 = Generation::query()->create([
        'user_id' => $user->id,
        'prompt' => 'Second succeeded generation same UTC day',
        'size' => '1024x1024',
        'quality' => 'low',
        'status' => Generation::STATUS_SUCCEEDED,
        'provider_id' => 'gen_check_2',
        'estimated_cost' => '0.020000000000',
        'provider_cost' => '0.014340000',
        'charged_cost' => '0.017925000000',
        'finished_at' => Carbon::parse('2026-11-17T23:30:00Z'),
    ]);

    CreditLedger::query()->create([
        'user_id' => $user->id,
        'generation_id' => $gen2->id,
        'type' => CreditLedger::TYPE_CHARGE,
        'amount' => '0.017925000000',
    ]);

    // Failed generation on same day should not be included in succeeded totals
    Generation::query()->create([
        'user_id' => $user->id,
        'prompt' => '[fail]',
        'size' => '1024x1024',
        'quality' => 'medium',
        'status' => Generation::STATUS_FAILED,
        'provider_id' => 'gen_check_failed',
        'estimated_cost' => '0.070000000000',
        'provider_cost' => '0.000000000',
        'charged_cost' => '0.000000000000',
        'finished_at' => Carbon::parse('2026-11-17T23:45:00Z'),
    ]);

    $this->artisan('money:check')
        ->expectsOutputToContain('provider_cost=0.305510000 | user_price=0.381887500000')
        ->expectsOutputToContain('provider_cost=0.014340000 | user_price=0.017925000000')
        ->expectsOutputToContain('2026-11-17: generations=2 | provider_cost=0.319850000 | user_price=0.399812500000')
        ->expectsOutputToContain('All succeeded generations passed: user price equals provider cost × 1.25 with zero rounding errors.')
        ->assertExitCode(0);
});
