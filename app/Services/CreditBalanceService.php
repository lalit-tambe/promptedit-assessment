<?php

namespace App\Services;

use App\Exceptions\InsufficientBalanceException;
use App\Models\CreditLedger;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreditBalanceService
{
    public const SCALE = 12;

    /**
     * Calculate a user's exact USD credit balance from the credit_ledger table.
     * Purchase rows add to the balance; charge rows subtract from it.
     *
     * @return numeric-string
     */
    public function balanceFor(User $user): string
    {
        $entries = CreditLedger::query()
            ->where('user_id', $user->id)
            ->get(['type', 'amount']);

        /** @var numeric-string $balance */
        $balance = '0.000000000000';

        foreach ($entries as $entry) {
            $amount = $this->normalizeDecimal((string) $entry->amount);

            if ($entry->type === CreditLedger::TYPE_PURCHASE) {
                $balance = bcadd($balance, $amount, self::SCALE);
            } elseif ($entry->type === CreditLedger::TYPE_CHARGE) {
                $balance = bcsub($balance, $amount, self::SCALE);
            }
        }

        return $balance;
    }

    /**
     * Sum estimated costs for a user's unfinished (queued or processing) generations.
     * Used during locked balance checks so concurrent requests cannot overspend a balance.
     *
     * @return numeric-string
     */
    public function pendingEstimatedFor(User $user): string
    {
        $pendingCosts = Generation::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [Generation::STATUS_QUEUED, Generation::STATUS_PROCESSING])
            ->pluck('estimated_cost');

        /** @var numeric-string $reserved */
        $reserved = '0.000000000000';

        foreach ($pendingCosts as $cost) {
            if ($cost !== null) {
                $reserved = bcadd($reserved, $this->normalizeDecimal((string) $cost), self::SCALE);
            }
        }

        return $reserved;
    }

    /**
     * Calculate available balance after accounting for in-flight generations.
     *
     * @return numeric-string
     */
    public function availableBalanceFor(User $user): string
    {
        return bcsub($this->balanceFor($user), $this->pendingEstimatedFor($user), self::SCALE);
    }

    /**
     * Record a fake credit purchase row in credit_ledger.
     */
    public function purchaseCredits(User $user, ?string $amount = null): CreditLedger
    {
        $rawAmount = $amount ?? (string) config('pixelforge.credit_purchase_amount', '5.000000000000');
        $purchaseAmount = $this->normalizeDecimal($rawAmount);

        return DB::transaction(function () use ($user, $purchaseAmount): CreditLedger {
            // Lock the user row to keep ledger updates serialized per user.
            /** @var User $lockedUser */
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);

            return CreditLedger::query()->create([
                'user_id' => $lockedUser->id,
                'generation_id' => null,
                'type' => CreditLedger::TYPE_PURCHASE,
                'amount' => $purchaseAmount,
            ]);
        });
    }

    /**
     * Lock the user row, verify exact available balance >= estimated cost, and create the queued generation.
     *
     * @param  array{prompt: string, size: string, quality: string, reference_urls: array<int, string>, estimated_cost: string}  $attributes
     *
     * @throws InsufficientBalanceException
     */
    public function createGeneration(User $user, array $attributes): Generation
    {
        $estimatedCost = $this->normalizeDecimal($attributes['estimated_cost']);

        return DB::transaction(function () use ($user, $attributes, $estimatedCost): Generation {
            // Lock the user row so two simultaneous requests cannot both pass the balance check.
            /** @var User $lockedUser */
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);

            $ledgerBalance = $this->balanceFor($lockedUser);
            $pendingReserved = $this->pendingEstimatedFor($lockedUser);
            $availableBalance = bcsub($ledgerBalance, $pendingReserved, self::SCALE);

            if (
                bccomp($ledgerBalance, $estimatedCost, self::SCALE) < 0
                || bccomp($availableBalance, $estimatedCost, self::SCALE) < 0
            ) {
                $displayAvailable = bccomp($availableBalance, '0', self::SCALE) < 0
                    ? '0.000000000000'
                    : $availableBalance;

                throw new InsufficientBalanceException(
                    availableBalance: $displayAvailable,
                    estimatedCost: $estimatedCost,
                    message: sprintf(
                        'Insufficient credit balance. Your available balance is %s, but this generation is estimated to cost %s. Please buy credits to continue.',
                        $this->formatUsd($displayAvailable, 6),
                        $this->formatUsd($estimatedCost, 6),
                    ),
                );
            }

            return Generation::query()->create([
                'user_id' => $lockedUser->id,
                'prompt' => $attributes['prompt'],
                'size' => $attributes['size'],
                'quality' => $attributes['quality'],
                'reference_urls' => $attributes['reference_urls'],
                'status' => Generation::STATUS_QUEUED,
                'estimated_cost' => $estimatedCost,
            ]);
        });
    }

    /**
     * Format an exact decimal USD string for display without using floats.
     */
    public function formatUsd(string $decimal, int $minDecimals = 2): string
    {
        $trimmed = trim($decimal);
        $isNegative = str_starts_with($trimmed, '-');
        $unsigned = ltrim($trimmed, '-+');

        [$whole, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $whole = $whole === '' ? '0' : $whole;
        $stripped = rtrim($fraction, '0');

        $visibleFraction = strlen($stripped) > $minDecimals
            ? $stripped
            : str_pad($stripped, $minDecimals, '0');

        return ($isNegative ? '-' : '').'$'.$whole.'.'.$visibleFraction;
    }

    /**
     * Ensure a decimal string is a valid numeric-string for bcmath.
     *
     * @return numeric-string
     */
    private function normalizeDecimal(string $value): string
    {
        $trimmed = trim($value);

        if (! is_numeric($trimmed)) {
            return '0.000000000000';
        }

        return bcadd($trimmed, '0', self::SCALE);
    }
}
