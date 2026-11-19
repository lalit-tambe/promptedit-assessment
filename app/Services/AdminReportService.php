<?php

namespace App\Services;

use App\Models\Generation;
use App\Services\Pixelforge\PixelforgeClient;

class AdminReportService
{
    public const PROVIDER_SCALE = 9;

    public const USER_SCALE = 12;

    public function __construct(
        private readonly PixelforgeClient $pixelforgeClient,
    ) {}

    /**
     * Build the admin report grouped by UTC completed day and by user,
     * excluding failed/unfinished generations, with exact bcmath totals and
     * optional reconciliation against GET /v1/usage.
     *
     * @return array{
     *     rows: array<int, array{
     *         date: string,
     *         user_id: int,
     *         user_name: string,
     *         user_email: string,
     *         generations_count: int,
     *         charged_cost: numeric-string,
     *         provider_cost: numeric-string,
     *         profit: numeric-string,
     *     }>,
     *     daily_totals: array<int, array{
     *         date: string,
     *         generations_count: int,
     *         charged_cost: numeric-string,
     *         provider_cost: numeric-string,
     *         profit: numeric-string,
     *         pixelforge_cost_usd: string|null,
     *         matches_pixelforge: bool|null,
     *     }>,
     *     totals: array{
     *         generations_count: int,
     *         charged_cost: numeric-string,
     *         provider_cost: numeric-string,
     *         profit: numeric-string,
     *     },
     *     usage_check: array{
     *         fetched: bool,
     *         error: string|null,
     *         start_date: string|null,
     *         end_date: string|null,
     *         total_pixelforge_cost_usd: string|null,
     *         all_days_match: bool|null,
     *     },
     * }
     */
    public function buildReport(bool $fetchProviderUsage = true): array
    {
        $succeeded = Generation::query()
            ->with('user:id,name,email')
            ->where('status', Generation::STATUS_SUCCEEDED)
            ->orderByDesc('finished_at')
            ->orderBy('id')
            ->get();

        /**
         * @var array<string, array{
         *     date: string,
         *     user_id: int,
         *     user_name: string,
         *     user_email: string,
         *     generations_count: int,
         *     charged_cost: numeric-string,
         *     provider_cost: numeric-string,
         *     profit: numeric-string,
         * }> $byDayAndUser
         */
        $byDayAndUser = [];

        /**
         * @var array<string, array{
         *     date: string,
         *     generations_count: int,
         *     charged_cost: numeric-string,
         *     provider_cost: numeric-string,
         *     profit: numeric-string,
         * }> $byDay
         */
        $byDay = [];

        $totalGenerations = 0;
        /** @var numeric-string $totalCharged */
        $totalCharged = '0.000000000000';
        /** @var numeric-string $totalProvider */
        $totalProvider = '0.000000000';

        foreach ($succeeded as $generation) {
            // Group by the UTC day the generation completed (not created), matching GET /v1/usage.
            $completedAt = $generation->finished_at ?? $generation->updated_at ?? $generation->created_at;
            $utcDate = $completedAt !== null
                ? $completedAt->copy()->utc()->format('Y-m-d')
                : 'unknown';

            $providerCost = $this->normalizeDecimal((string) ($generation->provider_cost ?? '0'), self::PROVIDER_SCALE);
            $chargedCost = $this->normalizeDecimal((string) ($generation->charged_cost ?? '0'), self::USER_SCALE);

            $userKey = "{$utcDate}|{$generation->user_id}";

            if (! isset($byDayAndUser[$userKey])) {
                $byDayAndUser[$userKey] = [
                    'date' => $utcDate,
                    'user_id' => $generation->user_id,
                    'user_name' => $generation->user->name ?? "User #{$generation->user_id}",
                    'user_email' => $generation->user->email ?? '',
                    'generations_count' => 0,
                    'charged_cost' => '0.000000000000',
                    'provider_cost' => '0.000000000',
                    'profit' => '0.000000000000',
                ];
            }

            $byDayAndUser[$userKey]['generations_count']++;
            $byDayAndUser[$userKey]['charged_cost'] = bcadd($byDayAndUser[$userKey]['charged_cost'], $chargedCost, self::USER_SCALE);
            $byDayAndUser[$userKey]['provider_cost'] = bcadd($byDayAndUser[$userKey]['provider_cost'], $providerCost, self::PROVIDER_SCALE);
            $byDayAndUser[$userKey]['profit'] = bcsub(
                $byDayAndUser[$userKey]['charged_cost'],
                $byDayAndUser[$userKey]['provider_cost'],
                self::USER_SCALE
            );

            if (! isset($byDay[$utcDate])) {
                $byDay[$utcDate] = [
                    'date' => $utcDate,
                    'generations_count' => 0,
                    'charged_cost' => '0.000000000000',
                    'provider_cost' => '0.000000000',
                    'profit' => '0.000000000000',
                ];
            }

            $byDay[$utcDate]['generations_count']++;
            $byDay[$utcDate]['charged_cost'] = bcadd($byDay[$utcDate]['charged_cost'], $chargedCost, self::USER_SCALE);
            $byDay[$utcDate]['provider_cost'] = bcadd($byDay[$utcDate]['provider_cost'], $providerCost, self::PROVIDER_SCALE);
            $byDay[$utcDate]['profit'] = bcsub(
                $byDay[$utcDate]['charged_cost'],
                $byDay[$utcDate]['provider_cost'],
                self::USER_SCALE
            );

            $totalGenerations++;
            $totalCharged = bcadd($totalCharged, $chargedCost, self::USER_SCALE);
            $totalProvider = bcadd($totalProvider, $providerCost, self::PROVIDER_SCALE);
        }

        $totalProfit = bcsub($totalCharged, $totalProvider, self::USER_SCALE);

        // Sort per-day-per-user rows by UTC date DESC, then user name ASC, then user_id ASC.
        $rows = array_values($byDayAndUser);
        usort($rows, static function (array $a, array $b): int {
            $dateCompare = strcmp($b['date'], $a['date']);
            if ($dateCompare !== 0) {
                return $dateCompare;
            }

            $nameCompare = strcasecmp($a['user_name'], $b['user_name']);
            if ($nameCompare !== 0) {
                return $nameCompare;
            }

            return $a['user_id'] <=> $b['user_id'];
        });

        krsort($byDay);

        $usageByDate = [];
        $usageCheck = [
            'fetched' => false,
            'error' => null,
            'start_date' => null,
            'end_date' => null,
            'total_pixelforge_cost_usd' => null,
            'all_days_match' => null,
        ];

        $dates = array_keys($byDay);
        if ($fetchProviderUsage && $dates !== [] && (string) config('pixelforge.api_key', '') !== '') {
            $startDate = end($dates);
            $endDate = reset($dates);
            $usageCheck['start_date'] = $startDate;
            $usageCheck['end_date'] = $endDate;

            try {
                $remoteUsage = $this->pixelforgeClient->getUsage($startDate, $endDate);
                $usageByDate = $this->extractDailyCosts($remoteUsage);
                $usageCheck['fetched'] = true;
            } catch (\Throwable $e) {
                $usageCheck['error'] = $e->getMessage();
            }
        }

        $dailyTotals = [];
        /** @var numeric-string $remoteTotal */
        $remoteTotal = '0.000000000';
        $allDaysMatch = true;

        foreach ($byDay as $date => $day) {
            $remoteCost = $usageCheck['fetched']
                ? ($usageByDate[$date] ?? '0.000000000')
                : null;

            $matches = $remoteCost !== null
                ? bccomp($day['provider_cost'], $remoteCost, self::PROVIDER_SCALE) === 0
                : null;

            if ($remoteCost !== null) {
                $remoteTotal = bcadd($remoteTotal, $remoteCost, self::PROVIDER_SCALE);
            }

            if ($matches === false) {
                $allDaysMatch = false;
            }

            $dailyTotals[] = [
                'date' => $day['date'],
                'generations_count' => $day['generations_count'],
                'charged_cost' => $day['charged_cost'],
                'provider_cost' => $day['provider_cost'],
                'profit' => $day['profit'],
                'pixelforge_cost_usd' => $remoteCost,
                'matches_pixelforge' => $matches,
            ];
        }

        if ($usageCheck['fetched']) {
            $usageCheck['total_pixelforge_cost_usd'] = $remoteTotal;
            $usageCheck['all_days_match'] = $allDaysMatch;
        }

        return [
            'rows' => $rows,
            'daily_totals' => $dailyTotals,
            'totals' => [
                'generations_count' => $totalGenerations,
                'charged_cost' => $totalCharged,
                'provider_cost' => $totalProvider,
                'profit' => $totalProfit,
            ],
            'usage_check' => $usageCheck,
        ];
    }

    /**
     * Extract date => cost_usd map from GET /v1/usage response payload.
     *
     * @param  array<string, mixed>  $response
     * @return array<string, numeric-string>
     */
    private function extractDailyCosts(array $response): array
    {
        $entries = [];

        if (is_array($response['data'] ?? null)) {
            $entries = $response['data'];
        } elseif (is_array($response['days'] ?? null)) {
            $entries = $response['days'];
        } elseif (is_array($response['usage'] ?? null)) {
            $entries = $response['usage'];
        }

        /** @var array<string, numeric-string> $byDate */
        $byDate = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $date = is_string($entry['date'] ?? null) ? $entry['date'] : null;
            $costUsd = isset($entry['cost_usd']) ? (string) $entry['cost_usd'] : null;

            if ($date !== null && $date !== '' && $costUsd !== null && is_numeric($costUsd)) {
                $byDate[$date] = $this->normalizeDecimal($costUsd, self::PROVIDER_SCALE);
            }
        }

        return $byDate;
    }

    /**
     * Normalize a numeric string to an exact fixed-scale decimal string for bcmath.
     *
     * @return numeric-string
     */
    private function normalizeDecimal(string $value, int $scale): string
    {
        $trimmed = trim($value);

        if (! is_numeric($trimmed)) {
            return bcadd('0', '0', $scale);
        }

        return bcadd($trimmed, '0', $scale);
    }
}
