<script setup lang="ts">
import { Head, Link, usePoll } from '@inertiajs/vue3';
import {
    AlertCircle,
    ArrowRight,
    CheckCircle2,
    Clock,
    ImageOff,
    Loader2,
    Sparkles,
} from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';

type GenerationStatus = 'queued' | 'processing' | 'succeeded' | 'failed';

type GenerationItem = {
    id: number;
    prompt: string;
    size: string;
    quality: string;
    status: GenerationStatus;
    estimated_cost: string;
    charged_cost: string | null;
    image_url: string | null;
    image_url_expires_at: string | null;
    error_message: string | null;
    created_at: string;
};

type DashboardStats = {
    total: number;
    succeeded: number;
    in_progress: number;
    failed: number;
    total_charged: string;
};

const props = withDefaults(
    defineProps<{
        stats?: DashboardStats;
        recentGenerations?: GenerationItem[];
        hasUnfinished?: boolean;
    }>(),
    {
        stats: () => ({
            total: 0,
            succeeded: 0,
            in_progress: 0,
            failed: 0,
            total_charged: '0.000000000000',
        }),
        recentGenerations: () => [],
        hasUnfinished: false,
    },
);

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});

const hasActiveGenerations = computed<boolean>(() => {
    if (props.hasUnfinished) {
        return true;
    }

    return props.recentGenerations.some(
        (g) => g.status === 'queued' || g.status === 'processing',
    );
});

const createPoll = usePoll;
const pollControl = createPoll(
    3000,
    {
        only: ['stats', 'recentGenerations', 'hasUnfinished'],
    },
    {
        autoStart: false,
    },
);

const isPolling = ref(false);

watch(
    hasActiveGenerations,
    (shouldPoll) => {
        if (shouldPoll && !isPolling.value) {
            pollControl.start();
            isPolling.value = true;
        } else if (!shouldPoll && isPolling.value) {
            pollControl.stop();
            isPolling.value = false;
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    if (isPolling.value) {
        pollControl.stop();
    }
});

const expiredImages = ref<Record<number, boolean>>({});

function markImageExpired(id: number): void {
    expiredImages.value[id] = true;
}

function isImageExpired(gen: GenerationItem): boolean {
    if (expiredImages.value[gen.id]) {
        return true;
    }

    if (gen.image_url_expires_at) {
        const ms = Date.parse(gen.image_url_expires_at);
        if (!Number.isNaN(ms) && ms <= Date.now()) {
            return true;
        }
    }

    return false;
}

function formatUsd(decimalString: string | null, minDecimals = 6): string {
    if (decimalString === null || decimalString.trim() === '') {
        return '—';
    }

    const trimmed = decimalString.trim();
    const [wholePart = '0', fractionPart = ''] = trimmed.split('.');
    const strippedTrailingZeros = fractionPart.replace(/0+$/, '');
    const visibleFraction =
        strippedTrailingZeros.length > minDecimals
            ? strippedTrailingZeros
            : strippedTrailingZeros.padEnd(minDecimals, '0');

    return `$${wholePart}.${visibleFraction}`;
}

function statusBadgeClasses(status: GenerationStatus): string {
    switch (status) {
        case 'queued':
            return 'border-amber-500/30 bg-amber-500/15 text-amber-800 dark:text-amber-300';
        case 'processing':
            return 'border-sky-500/30 bg-sky-500/15 text-sky-800 dark:text-sky-300';
        case 'succeeded':
            return 'border-emerald-500/30 bg-emerald-500/15 text-emerald-800 dark:text-emerald-300';
        case 'failed':
            return 'border-rose-500/30 bg-rose-500/15 text-rose-800 dark:text-rose-300';
    }
}
</script>

<template>
    <Head title="Dashboard" />

    <div class="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-6 p-4 sm:p-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Dashboard</h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Overview of your Pixelforge image generations and charges.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <Button as-child variant="outline">
                    <Link href="/generations">View All History</Link>
                </Button>
                <Button as-child>
                    <Link href="/generate">
                        <Sparkles class="mr-2 size-4" />
                        Generate Image
                    </Link>
                </Button>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl border bg-card p-5 shadow-xs">
                <span
                    class="text-xs font-medium text-muted-foreground uppercase"
                >
                    Total Generations
                </span>
                <div class="mt-2 text-2xl font-semibold">
                    {{ props.stats.total }}
                </div>
            </div>

            <div class="rounded-xl border bg-card p-5 shadow-xs">
                <span
                    class="text-xs font-medium text-muted-foreground uppercase"
                >
                    Succeeded
                </span>
                <div
                    class="mt-2 text-2xl font-semibold text-emerald-600 dark:text-emerald-400"
                >
                    {{ props.stats.succeeded }}
                </div>
            </div>

            <div class="rounded-xl border bg-card p-5 shadow-xs">
                <span
                    class="text-xs font-medium text-muted-foreground uppercase"
                >
                    In Progress / Failed
                </span>
                <div class="mt-2 text-2xl font-semibold">
                    {{ props.stats.in_progress }} active ·
                    {{ props.stats.failed }} failed
                </div>
            </div>

            <div class="rounded-xl border bg-card p-5 shadow-xs">
                <span
                    class="text-xs font-medium text-muted-foreground uppercase"
                >
                    Total Charged
                </span>
                <div class="mt-2 font-mono text-2xl font-semibold">
                    {{ formatUsd(props.stats.total_charged, 6) }}
                </div>
            </div>
        </div>

        <!-- Recent Generations -->
        <div class="flex flex-col gap-4">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-semibold">Recent Generations</h2>
                <Link
                    href="/generations"
                    class="inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline"
                >
                    See full history
                    <ArrowRight class="size-4" />
                </Link>
            </div>

            <div
                v-if="props.recentGenerations.length === 0"
                class="flex flex-col items-center justify-center gap-3 rounded-xl border bg-card p-12 text-center shadow-xs"
            >
                <p class="text-sm text-muted-foreground">
                    No generations yet. Create your first image to see it here.
                </p>
                <Button as-child size="sm">
                    <Link href="/generate">
                        <Sparkles class="mr-2 size-4" />
                        Generate Image
                    </Link>
                </Button>
            </div>

            <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="gen in props.recentGenerations"
                    :key="gen.id"
                    class="flex flex-col overflow-hidden rounded-xl border bg-card shadow-xs"
                >
                    <div
                        class="relative aspect-square w-full border-b bg-muted/20"
                    >
                        <img
                            v-if="gen.image_url && !isImageExpired(gen)"
                            :src="gen.image_url"
                            :alt="gen.prompt"
                            class="h-full w-full object-cover"
                            loading="lazy"
                            @error="markImageExpired(gen.id)"
                        />
                        <div
                            v-else-if="
                                (gen.image_url && isImageExpired(gen)) ||
                                (gen.status === 'succeeded' && !gen.image_url)
                            "
                            class="flex h-full w-full flex-col items-center justify-center gap-2 p-4 text-center text-muted-foreground"
                        >
                            <ImageOff class="size-6 opacity-70" />
                            <span class="text-xs font-medium">
                                Image link expired
                            </span>
                        </div>
                        <div
                            v-else-if="
                                gen.status === 'queued' ||
                                gen.status === 'processing'
                            "
                            class="flex h-full w-full flex-col items-center justify-center gap-2 p-4 text-center text-muted-foreground"
                        >
                            <Loader2
                                v-if="gen.status === 'processing'"
                                class="size-6 animate-spin text-sky-500"
                            />
                            <Clock v-else class="size-6 opacity-60" />
                            <span class="text-xs capitalize">
                                {{ gen.status }}...
                            </span>
                        </div>
                        <div
                            v-else
                            class="flex h-full w-full flex-col items-center justify-center gap-2 p-4 text-center text-rose-600 dark:text-rose-400"
                        >
                            <AlertCircle class="size-6 opacity-80" />
                            <span class="text-xs font-medium">Failed</span>
                        </div>
                    </div>

                    <div class="flex flex-1 flex-col justify-between gap-3 p-4">
                        <div class="space-y-2">
                            <div class="flex items-start justify-between gap-2">
                                <span
                                    class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-semibold capitalize"
                                    :class="statusBadgeClasses(gen.status)"
                                >
                                    <Loader2
                                        v-if="gen.status === 'processing'"
                                        class="size-3 animate-spin"
                                    />
                                    <CheckCircle2
                                        v-else-if="gen.status === 'succeeded'"
                                        class="size-3"
                                    />
                                    <AlertCircle
                                        v-else-if="gen.status === 'failed'"
                                        class="size-3"
                                    />
                                    <Clock v-else class="size-3" />
                                    {{ gen.status }}
                                </span>
                                <span
                                    class="font-mono text-xs text-muted-foreground"
                                >
                                    {{ gen.size }} · {{ gen.quality }}
                                </span>
                            </div>

                            <p class="line-clamp-2 text-sm font-medium">
                                {{ gen.prompt }}
                            </p>

                            <p
                                v-if="
                                    gen.status === 'failed' && gen.error_message
                                "
                                class="text-xs text-rose-600 dark:text-rose-400"
                            >
                                {{ gen.error_message }}
                            </p>
                        </div>

                        <div
                            class="flex items-center justify-between border-t pt-2.5 text-xs"
                        >
                            <span class="text-muted-foreground">
                                Est:
                                <strong class="font-mono text-foreground">
                                    {{ formatUsd(gen.estimated_cost, 6) }}
                                </strong>
                            </span>
                            <span class="text-muted-foreground">
                                Charged:
                                <strong class="font-mono text-foreground">
                                    {{
                                        gen.status === 'failed'
                                            ? '0 ($0.000000)'
                                            : gen.status === 'succeeded'
                                              ? formatUsd(gen.charged_cost, 6)
                                              : '—'
                                    }}
                                </strong>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
