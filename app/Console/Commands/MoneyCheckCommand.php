<?php

namespace App\Console\Commands;

use App\Models\CreditLedger;
use App\Models\Generation;
use Illuminate\Console\Command;

class MoneyCheckCommand extends Command
{
    protected $signature = 'money:check';

    protected $description = 'Verify exact provider_cost and charged_cost (provider_cost * 1.25) for all succeeded generations and print daily UTC provider cost totals.';

    public function handle(): int
    {
        $rawMarkup = (string) config('pixelforge.markup', '1.25');
        $markup = is_numeric($rawMarkup) ? $rawMarkup : '1.25';

        $succeeded = Generation::query()
            ->where('status', Generation::STATUS_SUCCEEDED)
            ->orderBy('id')
            ->get();

        if ($succeeded->isEmpty()) {
            $this->info('No succeeded generations found.');

            return self::SUCCESS;
        }

        $this->info('Succeeded Generations:');

        $rows = [];
        $allValid = true;
        /** @var array<string, array{count: int, provider_cost: numeric-string, charged_cost: numeric-string}> $dailyTotals */
        $dailyTotals = [];

        foreach ($succeeded as $generation) {
            $rawProviderCost = (string) ($generation->provider_cost ?? '0.000000000');
            $providerCost = is_numeric($rawProviderCost) ? $rawProviderCost : '0.000000000';

            $rawChargedCost = (string) ($generation->charged_cost ?? '0.000000000000');
            $chargedCost = is_numeric($rawChargedCost) ? $rawChargedCost : '0.000000000000';

            $expectedUserPrice = bcmul($providerCost, $markup, 12);

            $matchesFormula = bccomp($chargedCost, $expectedUserPrice, 12) === 0;

            $ledgerCount = CreditLedger::query()
                ->where('generation_id', $generation->id)
                ->where('type', CreditLedger::TYPE_CHARGE)
                ->count();

            $statusLabel = ($matchesFormula && $ledgerCount === 1) ? 'OK (×1.25 exact)' : 'MISMATCH';

            if (! $matchesFormula || $ledgerCount !== 1) {
                $allValid = false;
            }

            $utcDate = ($generation->finished_at ?? $generation->updated_at ?? $generation->created_at)
                ?->copy()
                ->utc()
                ->format('Y-m-d') ?? 'unknown';

            if (! isset($dailyTotals[$utcDate])) {
                $dailyTotals[$utcDate] = [
                    'count' => 0,
                    'provider_cost' => '0.000000000',
                    'charged_cost' => '0.000000000000',
                ];
            }

            $dailyTotals[$utcDate]['count']++;
            $dailyTotals[$utcDate]['provider_cost'] = bcadd(
                $dailyTotals[$utcDate]['provider_cost'],
                $providerCost,
                9
            );
            $dailyTotals[$utcDate]['charged_cost'] = bcadd(
                $dailyTotals[$utcDate]['charged_cost'],
                $chargedCost,
                12
            );

            $rows[] = [
                (string) $generation->id,
                (string) ($generation->provider_id ?? '-'),
                $utcDate,
                $providerCost,
                $chargedCost,
                $expectedUserPrice,
                (string) $ledgerCount,
                $statusLabel,
            ];

            $this->line("Generation #{$generation->id} ({$generation->provider_id}) [{$utcDate}]: provider_cost={$providerCost} | user_price={$chargedCost} | expected={$expectedUserPrice} | ledger_rows={$ledgerCount} | {$statusLabel}");
        }

        $this->table(
            ['ID', 'Provider ID', 'Completed (UTC)', 'Provider Cost (9d)', 'User Price (12d)', 'Expected ×1.25', 'Ledger Rows', 'Check'],
            $rows
        );

        ksort($dailyTotals);

        $this->newLine();
        $this->info('Total Provider Cost per UTC Completed Day (compare with GET /v1/usage):');

        $dailyRows = [];
        foreach ($dailyTotals as $date => $totals) {
            $dailyRows[] = [
                $date,
                (string) $totals['count'],
                $totals['provider_cost'],
                $totals['charged_cost'],
            ];

            $this->line("{$date}: generations={$totals['count']} | provider_cost={$totals['provider_cost']} | user_price={$totals['charged_cost']}");
        }

        $this->table(
            ['UTC Completed Date', 'Succeeded Generations', 'Total Provider Cost (cost_usd)', 'Total User Price Charged'],
            $dailyRows
        );

        if (! $allValid) {
            $this->error('One or more generations failed the exact money check.');

            return self::FAILURE;
        }

        $this->info('All succeeded generations passed: user price equals provider cost × 1.25 with zero rounding errors.');

        return self::SUCCESS;
    }
}
