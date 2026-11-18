<?php

namespace App\Services\Pixelforge;

use App\Models\Generation;
use InvalidArgumentException;

class PricingCalculator
{
    public const PROVIDER_COST_SCALE = 9;

    public const USER_PRICE_SCALE = 12;

    public const TOKENS_UNIT = '1000000';

    /**
     * Estimate tokens, provider cost (9 decimals), and user price (12 decimals) before submission.
     * Uses ceil(characters / 4) in Unicode characters and the midpoint of the quality's reasoning range.
     *
     * @param  array<string, string>|null  $rates
     * @return array{
     *     tokens: array{
     *         input_text_tokens: int,
     *         input_image_tokens: int,
     *         output_tokens: int,
     *         reasoning_tokens: int,
     *         total_tokens: int
     *     },
     *     provider_cost: string,
     *     user_price: string
     * }
     */
    public function estimate(
        string $prompt,
        string $size = '1024x1024',
        string $quality = 'medium',
        int $referenceImageCount = 0,
        ?array $rates = null,
    ): array {
        $textTokens = $this->estimateTextTokens($prompt);
        $imageTokens = $this->imageTokens($referenceImageCount);
        $outputTokens = $this->outputTokens($size, $quality);
        [$minReasoning, $maxReasoning] = $this->reasoningBounds($quality);
        $reasoningTokens = intdiv($minReasoning + $maxReasoning, 2);

        return $this->buildCalculationResult(
            $textTokens,
            $imageTokens,
            $outputTokens,
            $reasoningTokens,
            $rates,
        );
    }

    /**
     * Calculate the worst-case estimate using text tokens +15% and the maximum reasoning tokens for the quality.
     *
     * @param  array<string, string>|null  $rates
     * @return array{
     *     tokens: array{
     *         input_text_tokens: int,
     *         input_image_tokens: int,
     *         output_tokens: int,
     *         reasoning_tokens: int,
     *         total_tokens: int
     *     },
     *     provider_cost: string,
     *     user_price: string
     * }
     */
    public function maxEstimate(
        string $prompt,
        string $size = '1024x1024',
        string $quality = 'medium',
        int $referenceImageCount = 0,
        ?array $rates = null,
    ): array {
        $baseTextTokens = $this->estimateTextTokens($prompt);
        $variancePercent = (int) config('pixelforge.tokens.text_token_variance_percent', 15);
        $maxTextTokens = intdiv($baseTextTokens * (100 + $variancePercent) + 99, 100);

        $imageTokens = $this->imageTokens($referenceImageCount);
        $outputTokens = $this->outputTokens($size, $quality);
        [, $maxReasoning] = $this->reasoningBounds($quality);

        return $this->buildCalculationResult(
            $maxTextTokens,
            $imageTokens,
            $outputTokens,
            $maxReasoning,
            $rates,
        );
    }

    /**
     * Calculate the exact Pixelforge provider cost (9 decimal places) from token usage and per-1M rates.
     * Returns '0.000000000' when usage is null or empty (e.g. failed generations).
     *
     * @param  array<string, int|string>|null  $usage
     * @param  array<string, string>|null  $rates
     */
    public function providerCost(?array $usage, ?array $rates = null): string
    {
        if (empty($usage)) {
            return bcadd('0', '0', self::PROVIDER_COST_SCALE);
        }

        $resolvedRates = $this->resolveRates($rates);

        $textTokens = (string) ((int) ($usage['input_text_tokens'] ?? 0));
        $imageTokens = (string) ((int) ($usage['input_image_tokens'] ?? 0));
        $outputTokens = (string) ((int) ($usage['output_tokens'] ?? 0));
        $reasoningTokens = (string) ((int) ($usage['reasoning_tokens'] ?? 0));

        $textPart = bcmul($textTokens, $resolvedRates['input_text_tokens'], self::PROVIDER_COST_SCALE);
        $imagePart = bcmul($imageTokens, $resolvedRates['input_image_tokens'], self::PROVIDER_COST_SCALE);
        $outputPart = bcmul($outputTokens, $resolvedRates['output_tokens'], self::PROVIDER_COST_SCALE);
        $reasoningPart = bcmul($reasoningTokens, $resolvedRates['reasoning_tokens'], self::PROVIDER_COST_SCALE);

        $numerator = bcadd(
            bcadd($textPart, $imagePart, self::PROVIDER_COST_SCALE),
            bcadd($outputPart, $reasoningPart, self::PROVIDER_COST_SCALE),
            self::PROVIDER_COST_SCALE
        );

        return bcdiv($numerator, self::TOKENS_UNIT, self::PROVIDER_COST_SCALE);
    }

    /**
     * Calculate the exact user price (12 decimal places) by multiplying providerCost by the configured 1.25 markup.
     */
    public function userPrice(string $providerCost): string
    {
        $numericCost = $this->numericString($providerCost, '0.000000000');
        $markup = $this->numericString((string) config('pixelforge.markup', '1.25'), '1.25');

        return bcmul($numericCost, $markup, self::USER_PRICE_SCALE);
    }

    /**
     * Calculate exact provider cost and user price for a finished generation payload or model.
     * Failed generations always cost 0.
     *
     * @param  Generation|array<string, mixed>  $generation
     * @return array{provider_cost: string, user_price: string}
     */
    public function forFinishedGeneration(Generation|array $generation): array
    {
        $status = $generation instanceof Generation
            ? $generation->status
            : (string) ($generation['status'] ?? '');

        if ($status !== Generation::STATUS_SUCCEEDED) {
            $zeroProvider = bcadd('0', '0', self::PROVIDER_COST_SCALE);

            return [
                'provider_cost' => $zeroProvider,
                'user_price' => $this->userPrice($zeroProvider),
            ];
        }

        if ($generation instanceof Generation) {
            $usage = [
                'input_text_tokens' => (int) ($generation->input_text_tokens ?? 0),
                'input_image_tokens' => (int) ($generation->input_image_tokens ?? 0),
                'output_tokens' => (int) ($generation->output_tokens ?? 0),
                'reasoning_tokens' => (int) ($generation->reasoning_tokens ?? 0),
            ];
            $rates = $generation->rates;
        } else {
            /** @var array<string, int|string>|null $usage */
            $usage = is_array($generation['usage'] ?? null) ? $generation['usage'] : null;
            /** @var array<string, string>|null $rates */
            $rates = is_array($generation['rates'] ?? null) ? $generation['rates'] : null;
        }

        $providerCost = $this->providerCost($usage, $rates);

        return [
            'provider_cost' => $providerCost,
            'user_price' => $this->userPrice($providerCost),
        ];
    }

    protected function estimateTextTokens(string $prompt): int
    {
        $characters = mb_strlen($prompt, 'UTF-8');
        $charsPerToken = max(1, (int) config('pixelforge.tokens.characters_per_text_token', 4));

        return intdiv($characters + $charsPerToken - 1, $charsPerToken);
    }

    protected function imageTokens(int $referenceImageCount): int
    {
        $perImage = (int) config('pixelforge.tokens.per_reference_image', 765);

        return max(0, $referenceImageCount) * $perImage;
    }

    protected function outputTokens(string $size, string $quality): int
    {
        /** @var array<string, array<string, int>> $table */
        $table = (array) config('pixelforge.tokens.output', []);

        if (! isset($table[$quality][$size])) {
            throw new InvalidArgumentException("Unsupported size [{$size}] or quality [{$quality}].");
        }

        return (int) $table[$quality][$size];
    }

    /**
     * @return array{0: int, 1: int}
     */
    protected function reasoningBounds(string $quality): array
    {
        /** @var array<string, array{min: int, max: int}> $ranges */
        $ranges = (array) config('pixelforge.tokens.reasoning', []);

        if (! isset($ranges[$quality])) {
            throw new InvalidArgumentException("Unsupported quality [{$quality}].");
        }

        return [
            (int) $ranges[$quality]['min'],
            (int) $ranges[$quality]['max'],
        ];
    }

    /**
     * @param  array<string, string>|null  $rates
     * @return array{
     *     input_text_tokens: numeric-string,
     *     input_image_tokens: numeric-string,
     *     output_tokens: numeric-string,
     *     reasoning_tokens: numeric-string
     * }
     */
    protected function resolveRates(?array $rates): array
    {
        /** @var array<string, string> $defaultRates */
        $defaultRates = (array) config('pixelforge.rates', [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ]);

        return [
            'input_text_tokens' => $this->numericString((string) ($rates['input_text_tokens'] ?? $defaultRates['input_text_tokens'] ?? '5.00'), '5.00'),
            'input_image_tokens' => $this->numericString((string) ($rates['input_image_tokens'] ?? $defaultRates['input_image_tokens'] ?? '10.00'), '10.00'),
            'output_tokens' => $this->numericString((string) ($rates['output_tokens'] ?? $defaultRates['output_tokens'] ?? '40.00'), '40.00'),
            'reasoning_tokens' => $this->numericString((string) ($rates['reasoning_tokens'] ?? $defaultRates['reasoning_tokens'] ?? '12.50'), '12.50'),
        ];
    }

    /**
     * @param  numeric-string  $fallback
     * @return numeric-string
     */
    protected function numericString(string $value, string $fallback = '0'): string
    {
        return is_numeric($value) ? $value : $fallback;
    }

    /**
     * @param  array<string, string>|null  $rates
     * @return array{
     *     tokens: array{
     *         input_text_tokens: int,
     *         input_image_tokens: int,
     *         output_tokens: int,
     *         reasoning_tokens: int,
     *         total_tokens: int
     *     },
     *     provider_cost: string,
     *     user_price: string
     * }
     */
    protected function buildCalculationResult(
        int $textTokens,
        int $imageTokens,
        int $outputTokens,
        int $reasoningTokens,
        ?array $rates,
    ): array {
        $tokens = [
            'input_text_tokens' => $textTokens,
            'input_image_tokens' => $imageTokens,
            'output_tokens' => $outputTokens,
            'reasoning_tokens' => $reasoningTokens,
            'total_tokens' => $textTokens + $imageTokens + $outputTokens + $reasoningTokens,
        ];

        $providerCost = $this->providerCost($tokens, $rates);
        $userPrice = $this->userPrice($providerCost);

        return [
            'tokens' => $tokens,
            'provider_cost' => $providerCost,
            'user_price' => $userPrice,
        ];
    }
}
