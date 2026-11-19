<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { AlertTriangle, CheckCircle2, ShieldCheck } from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import { formatUsd } from '@/lib/money';

type DayUserRow = {
    date: string;
    user_id: number;
    user_name: string;
    user_email: string;
    generations_count: number;
    charged_cost: string;
    provider_cost: string;
    profit: string;
};

type DailyTotalRow = {
    date: string;
    generations_count: number;
    charged_cost: string;
    provider_cost: string;
    profit: string;
    pixelforge_cost_usd: string | null;
    matches_pixelforge: boolean | null;
};

type ReportTotals = {
    generations_count: number;
    charged_cost: string;
    provider_cost: string;
    profit: string;
};

type UsageCheck = {
    fetched: boolean;
    error: string | null;
    start_date: string | null;
    end_date: string | null;
    total_pixelforge_cost_usd: string | null;
    all_days_match: boolean | null;
};

type Props = {
    rows: DayUserRow[];
    daily_totals: DailyTotalRow[];
    totals: ReportTotals;
    usage_check: UsageCheck;
};

const props = defineProps<Props>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Admin Report',
                href: '/admin/report',
            },
        ],
    },
});
</script>

<template>
    <Head title="Admin Report" />

    <div class="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-6 p-4 sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <div class="flex items-center gap-2">
                    <ShieldCheck class="size-5 text-primary" />
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Admin Report
                    </h1>
                </div>
                <p class="mt-1 text-sm text-muted-foreground">
                    Succeeded generations grouped by UTC completion date and
                    user. Failed generations are excluded.
                </p>
            </div>

            <div
                v-if="props.usage_check.fetched"
                class="flex items-center gap-2"
                data-testid="usage-reconciliation-badge"
            >
                <Badge
                    v-if="props.usage_check.all_days_match"
                    variant="secondary"
                    class="gap-1.5 border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300"
                >
                    <CheckCircle2 class="size-3.5" />
                    Matches GET /v1/usage
                </Badge>
                <Badge v-else variant="destructive" class="gap-1.5">
                    <AlertTriangle class="size-3.5" />
                    Differs from GET /v1/usage
                </Badge>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl border bg-card p-4 shadow-xs">
                <div class="text-xs font-medium text-muted-foreground">
                    Succeeded Generations
                </div>
                <div
                    class="mt-1 font-mono text-2xl font-semibold"
                    data-testid="total-generations"
                >
                    {{ props.totals.generations_count }}
                </div>
            </div>

            <div class="rounded-xl border bg-card p-4 shadow-xs">
                <div class="text-xs font-medium text-muted-foreground">
                    Credits Charged to Users
                </div>
                <div
                    class="mt-1 font-mono text-2xl font-semibold"
                    data-testid="total-charged"
                >
                    {{ formatUsd(props.totals.charged_cost, 6) }}
                </div>
            </div>

            <div class="rounded-xl border bg-card p-4 shadow-xs">
                <div class="text-xs font-medium text-muted-foreground">
                    Pixelforge Cost
                </div>
                <div
                    class="mt-1 font-mono text-2xl font-semibold"
                    data-testid="total-provider-cost"
                >
                    {{ formatUsd(props.totals.provider_cost, 9) }}
                </div>
            </div>

            <div class="rounded-xl border bg-card p-4 shadow-xs">
                <div class="text-xs font-medium text-muted-foreground">
                    Profit (Charged − Cost)
                </div>
                <div
                    class="mt-1 font-mono text-2xl font-semibold text-emerald-600 dark:text-emerald-400"
                    data-testid="total-profit"
                >
                    {{ formatUsd(props.totals.profit, 6) }}
                </div>
            </div>
        </div>

        <!-- Per UTC Day & Per User Table -->
        <section class="rounded-xl border bg-card shadow-xs">
            <div class="border-b px-5 py-4">
                <h2 class="text-base font-semibold">
                    Breakdown by UTC Completed Day &amp; User
                </h2>
                <p class="mt-0.5 text-xs text-muted-foreground">
                    Exact decimal sums per UTC completion day and user.
                </p>
            </div>

            <div class="overflow-x-auto">
                <table
                    class="w-full text-left text-sm"
                    data-testid="admin-report-table"
                >
                    <thead
                        class="border-b bg-muted/40 text-xs font-medium text-muted-foreground"
                    >
                        <tr>
                            <th class="px-5 py-3">UTC Date</th>
                            <th class="px-5 py-3">User</th>
                            <th class="px-5 py-3 text-right">Succeeded</th>
                            <th class="px-5 py-3 text-right">
                                Credits Charged
                            </th>
                            <th class="px-5 py-3 text-right">
                                Pixelforge Cost
                            </th>
                            <th class="px-5 py-3 text-right">Profit</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y">
                        <tr v-if="props.rows.length === 0">
                            <td
                                colspan="6"
                                class="px-5 py-8 text-center text-sm text-muted-foreground"
                            >
                                No succeeded generations yet.
                            </td>
                        </tr>

                        <tr
                            v-for="row in props.rows"
                            :key="`${row.date}-${row.user_id}`"
                            class="hover:bg-muted/20"
                        >
                            <td class="px-5 py-3 font-mono text-xs">
                                {{ row.date }}
                            </td>
                            <td class="px-5 py-3">
                                <div class="font-medium text-foreground">
                                    {{ row.user_name }}
                                </div>
                                <div class="text-xs text-muted-foreground">
                                    {{ row.user_email }}
                                </div>
                            </td>
                            <td class="px-5 py-3 text-right font-mono">
                                {{ row.generations_count }}
                            </td>
                            <td class="px-5 py-3 text-right font-mono">
                                {{ formatUsd(row.charged_cost, 6) }}
                            </td>
                            <td class="px-5 py-3 text-right font-mono">
                                {{ formatUsd(row.provider_cost, 9) }}
                            </td>
                            <td
                                class="px-5 py-3 text-right font-mono text-emerald-600 dark:text-emerald-400"
                            >
                                {{ formatUsd(row.profit, 6) }}
                            </td>
                        </tr>
                    </tbody>

                    <tfoot
                        class="border-t bg-muted/50 font-semibold text-foreground"
                        data-testid="admin-report-total-row"
                    >
                        <tr>
                            <td class="px-5 py-3" colspan="2">Total</td>
                            <td class="px-5 py-3 text-right font-mono">
                                {{ props.totals.generations_count }}
                            </td>
                            <td class="px-5 py-3 text-right font-mono">
                                {{ formatUsd(props.totals.charged_cost, 6) }}
                            </td>
                            <td class="px-5 py-3 text-right font-mono">
                                {{ formatUsd(props.totals.provider_cost, 9) }}
                            </td>
                            <td
                                class="px-5 py-3 text-right font-mono text-emerald-600 dark:text-emerald-400"
                            >
                                {{ formatUsd(props.totals.profit, 6) }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>

        <!-- Daily Totals vs GET /v1/usage -->
        <section
            v-if="props.daily_totals.length > 0"
            class="rounded-xl border bg-card shadow-xs"
        >
            <div class="border-b px-5 py-4">
                <h2 class="text-base font-semibold">
                    Daily UTC Totals vs. Pixelforge <code>GET /v1/usage</code>
                </h2>
                <p
                    v-if="props.usage_check.error"
                    class="mt-0.5 text-xs text-destructive"
                >
                    Could not fetch live /v1/usage:
                    {{ props.usage_check.error }}
                </p>
                <p v-else class="mt-0.5 text-xs text-muted-foreground">
                    Provider cost stored with full 9-decimal precision for exact
                    reconciliation.
                </p>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead
                        class="border-b bg-muted/40 text-xs font-medium text-muted-foreground"
                    >
                        <tr>
                            <th class="px-5 py-3">UTC Date</th>
                            <th class="px-5 py-3 text-right">Succeeded</th>
                            <th class="px-5 py-3 text-right">
                                Credits Charged
                            </th>
                            <th class="px-5 py-3 text-right">
                                Local Provider Cost
                            </th>
                            <th class="px-5 py-3 text-right">
                                Pixelforge /v1/usage
                            </th>
                            <th class="px-5 py-3 text-right">Profit</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y">
                        <tr
                            v-for="day in props.daily_totals"
                            :key="day.date"
                            class="hover:bg-muted/20"
                        >
                            <td class="px-5 py-3 font-mono text-xs">
                                {{ day.date }}
                            </td>
                            <td class="px-5 py-3 text-right font-mono">
                                {{ day.generations_count }}
                            </td>
                            <td class="px-5 py-3 text-right font-mono">
                                {{ formatUsd(day.charged_cost, 6) }}
                            </td>
                            <td class="px-5 py-3 text-right font-mono">
                                {{ formatUsd(day.provider_cost, 9) }}
                            </td>
                            <td class="px-5 py-3 text-right font-mono">
                                <template
                                    v-if="day.pixelforge_cost_usd !== null"
                                >
                                    <span>{{
                                        formatUsd(day.pixelforge_cost_usd, 9)
                                    }}</span>
                                    <span
                                        v-if="day.matches_pixelforge"
                                        class="ml-1.5 text-xs text-emerald-600 dark:text-emerald-400"
                                        >✓</span
                                    >
                                    <span
                                        v-else
                                        class="ml-1.5 text-xs text-destructive"
                                        >✗</span
                                    >
                                </template>
                                <span v-else class="text-muted-foreground"
                                    >—</span
                                >
                            </td>
                            <td
                                class="px-5 py-3 text-right font-mono text-emerald-600 dark:text-emerald-400"
                            >
                                {{ formatUsd(day.profit, 6) }}
                            </td>
                        </tr>
                    </tbody>

                    <tfoot class="border-t bg-muted/50 font-semibold">
                        <tr>
                            <td class="px-5 py-3">Total</td>
                            <td class="px-5 py-3 text-right font-mono">
                                {{ props.totals.generations_count }}
                            </td>
                            <td class="px-5 py-3 text-right font-mono">
                                {{ formatUsd(props.totals.charged_cost, 6) }}
                            </td>
                            <td class="px-5 py-3 text-right font-mono">
                                {{ formatUsd(props.totals.provider_cost, 9) }}
                            </td>
                            <td class="px-5 py-3 text-right font-mono">
                                <template
                                    v-if="
                                        props.usage_check
                                            .total_pixelforge_cost_usd !== null
                                    "
                                >
                                    {{
                                        formatUsd(
                                            props.usage_check
                                                .total_pixelforge_cost_usd,
                                            9,
                                        )
                                    }}
                                </template>
                                <span v-else class="text-muted-foreground"
                                    >—</span
                                >
                            </td>
                            <td
                                class="px-5 py-3 text-right font-mono text-emerald-600 dark:text-emerald-400"
                            >
                                {{ formatUsd(props.totals.profit, 6) }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>
    </div>
</template>
