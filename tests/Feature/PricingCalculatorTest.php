<?php

use App\Models\Generation;
use App\Services\Pixelforge\PricingCalculator;

it('calculates exact provider cost and user price for a succeeded generation', function () {
    $calculator = new PricingCalculator;

    $usage = [
        'input_text_tokens' => 12,
        'input_image_tokens' => 765,
        'output_tokens' => 6450,
        'reasoning_tokens' => 3184,
    ];

    $rates = [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ];

    $providerCost = $calculator->providerCost($usage, $rates);
    $userPrice = $calculator->userPrice($providerCost);

    expect($providerCost)->toBe('0.305510000')
        ->and($userPrice)->toBe('0.381887500000');
});

it('calculates the worst-case maxEstimate matching the docs example', function () {
    $calculator = new PricingCalculator;

    $prompt = str_repeat('a', 400);
    $rates = [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ];

    $result = $calculator->maxEstimate($prompt, '1536x1024', 'high', 2, $rates);

    expect($result['tokens'])->toBe([
        'input_text_tokens' => 115,
        'input_image_tokens' => 1530,
        'output_tokens' => 6450,
        'reasoning_tokens' => 8000,
        'total_tokens' => 16095,
    ])
        ->and($result['provider_cost'])->toBe('0.373875000')
        ->and($result['user_price'])->toBe('0.467343750000');
});

it('estimates cost using mb_strlen for Unicode characters and midpoint reasoning across size and quality choices', function () {
    $calculator = new PricingCalculator;

    // 10 multibyte Unicode characters -> ceil(10 / 4) = 3 text tokens
    $unicodePrompt = str_repeat('🎨', 10);

    $lowSquare = $calculator->estimate($unicodePrompt, '1024x1024', 'low', 0);
    // text: 3 * 5 = 15, image: 0, output: 280 * 40 = 11200, reasoning midpoint: 250 * 12.50 = 3125 -> total = 14340 / 1M = 0.014340000
    expect($lowSquare['tokens']['input_text_tokens'])->toBe(3)
        ->and($lowSquare['tokens']['output_tokens'])->toBe(280)
        ->and($lowSquare['tokens']['reasoning_tokens'])->toBe(250)
        ->and($lowSquare['provider_cost'])->toBe('0.014340000')
        ->and($lowSquare['user_price'])->toBe('0.017925000000');

    $mediumLandscape = $calculator->estimate($unicodePrompt, '1536x1024', 'medium', 1);
    // text: 3 * 5 = 15, image: 765 * 10 = 7650, output: 1650 * 40 = 66000, reasoning midpoint: 1100 * 12.50 = 13750 -> total = 87415 / 1M = 0.087415000
    expect($mediumLandscape['tokens']['input_image_tokens'])->toBe(765)
        ->and($mediumLandscape['tokens']['output_tokens'])->toBe(1650)
        ->and($mediumLandscape['tokens']['reasoning_tokens'])->toBe(1100)
        ->and($mediumLandscape['provider_cost'])->toBe('0.087415000')
        ->and($mediumLandscape['user_price'])->toBe('0.109268750000');
});

it('charges 0 for a failed generation', function () {
    $calculator = new PricingCalculator;

    $providerCost = $calculator->providerCost(null);
    $userPrice = $calculator->userPrice($providerCost);

    $fromFinished = $calculator->forFinishedGeneration([
        'status' => Generation::STATUS_FAILED,
        'usage' => null,
    ]);

    expect($providerCost)->toBe('0.000000000')
        ->and($userPrice)->toBe('0.000000000000')
        ->and($fromFinished['provider_cost'])->toBe('0.000000000')
        ->and($fromFinished['user_price'])->toBe('0.000000000000');
});
