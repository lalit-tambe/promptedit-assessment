<script setup lang="ts">
import { Head, Link, usePoll } from '@inertiajs/vue3';
import {
    AlertCircle,
    CheckCircle2,
    Clock,
    ImageOff,
    Loader2,
    Sparkles,
} from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';

type GenerationStatus = 'queued' | 'processing' | 'succeeded' | 'failed';

type GenerationItem = {
    id: number;
    prompt: string;
    size: string;
    quality: string;
    reference_urls: string[] | null;
    status: GenerationStatus;
    provider_id: string | null;
    estimated_cost: string;
    provider_cost: string | null;
    charged_cost: string | null;
    input_text_tokens: number | null;
    input_image_tokens: number | null;
    output_tokens: number | null;
    reasoning_tokens: number | null;
    total_tokens: number | null;
    image_url: string | null;
    image_url_expires_at: string | null;
    error_code: string | null;
    error_message: string | null;
    created_at: string;
    finished_at: string | null;
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

type PaginatedGenerations = {
    data: GenerationItem[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    prev_page_url: string | null;
    next_page_url: string | null;
    links: PaginationLink[];
};

const props = defineProps<{
    generations: PaginatedGenerations | GenerationItem[];
    hasUnfinished?: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'History',
                href: '/generations',
            },
        ],
    },
});

const items = computed<GenerationItem[]>(() =>
    Array.isArray(props.generations)
        ? props.generations
        : props.generations.data,
);

const pagination = computed<PaginatedGenerations | null>(() =>
    Array.isArray(props.generations) ? null : props.generations,
);

const hasActiveGenerations = computed<boolean>(() => {
    if (props.hasUnfinished) {
        return true;
    }

    return items.value.some(
        (gen) => gen.status === 'queued' || gen.status === 'processing',
    );
});

// Poll every 3 seconds only while at least one generation is queued or processing
const createPoll = usePoll;
const pollControl = createPoll(
    3000,
    {
        only: ['generations', 'hasUnfinished'],
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

function markImageExpired(generationId: number): void {
    expiredImages.value[generationId] = true;
}

function isImageExpired(gen: GenerationItem): boolean {
    if (expiredImages.value[gen.id]) {
        return true;
    }

    if (gen.image_url_expires_at) {
        const expiresAtMs = Date.parse(gen.image_url_expires_at);
        if (!Number.isNaN(expiresAtMs) && expiresAtMs <= Date.now()) {
            return true;
        }
    }

    return false;
}

function formatUsd(decimalString: string | null, minDecimals = 6): string {
    if (decimalString === null || decimalString.trim() === '') {
        return '';
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

function formatChargedCost(gen: GenerationItem): string {
    if (gen.status === 'queued' || gen.status === 'processing') {
        return '';
    }

    if (gen.status === 'failed') {
        return '0 ($0.000000)';
    }

    return formatUsd(gen.charged_cost, 6);
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

function formatTimestamp(isoString: string | null): string {
    if (!isoString) {
        return '';
    }

    const date = new Date(isoString);
    if (Number.isNaN(date.getTime())) {
        return isoString;
    }

    return date.toLocaleString();
}
</script>

<template>
    <Head title="Generation History" />

    <div class="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6 p-4 sm:p-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <div class="flex items-center gap-3">
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Generation History
                    </h1>
                    <span
                        v-if="hasActiveGenerations"
                        class="inline-flex items-center gap-1.5 rounded-full border border-sky-500/30 bg-sky-500/10 px-2.5 py-0.5 text-xs font-medium text-sky-700 dark:text-sky-300"
                        data-testid="live-polling-indicator"
                    >
                        <Loader2 class="size-3 animate-spin" />
                        Live updating
                    </span>
                </div>
                <p class="mt-1 text-sm text-muted-foreground">
                    Your generated images, statuses, and exact charges.
                </p>
            </div>
            <Button as-child>
                <Link href="/generate">
                    <Sparkles class="mr-2 size-4" />
                    New Generation
                </Link>
            </Button>
        </div>

        <div
            v-if="items.length === 0"
            class="flex flex-col items-center justify-center gap-3 rounded-xl border bg-card p-12 text-center shadow-xs"
        >
            <p class="text-sm text-muted-foreground">
                You haven't created any image generations yet.
            </p>
            <Button as-child variant="outline" size="sm">
                <Link href="/generate">Create your first image</Link>
            </Button>
        </div>

        <div v-else class="grid gap-4">
            <article
                v-for="gen in items"
                :key="gen.id"
                class="flex flex-col gap-4 rounded-xl border bg-card p-5 shadow-xs sm:flex-row sm:items-start"
                :data-testid="`generation-row-${gen.id}`"
            >
                <!-- Image Preview or Placeholder -->
                <div
                    class="w-full shrink-0 sm:w-44"
                    :data-testid="`generation-media-${gen.id}`"
                >
                    <!-- Succeeded with active image URL -->
                    <div
                        v-if="gen.image_url && !isImageExpired(gen)"
                        class="overflow-hidden rounded-lg border bg-muted/30"
                    >
                        <img
                            :src="gen.image_url"
                            :alt="gen.prompt"
                            class="aspect-square w-full object-cover"
                            loading="lazy"
                            @error="markImageExpired(gen.id)"
                        />
                    </div>

                    <!-- Expired or broken image URL -->
                    <div
                        v-else-if="
                            (gen.image_url && isImageExpired(gen)) ||
                            (gen.status === 'succeeded' && !gen.image_url)
                        "
                        class="flex aspect-square w-full flex-col items-center justify-center gap-2 rounded-lg border border-dashed bg-muted/40 p-4 text-center text-muted-foreground"
                        data-testid="expired-image-placeholder"
                    >
                        <ImageOff class="size-6 opacity-70" />
                        <span class="text-xs font-medium">
                            Image link expired
                        </span>
                    </div>

                    <!-- Queued or Processing placeholder -->
                    <div
                        v-else-if="
                            gen.status === 'queued' ||
                            gen.status === 'processing'
                        "
                        class="flex aspect-square w-full flex-col items-center justify-center gap-2 rounded-lg border bg-muted/20 p-4 text-center text-muted-foreground"
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

                    <!-- Failed placeholder -->
                    <div
                        v-else
                        class="flex aspect-square w-full flex-col items-center justify-center gap-2 rounded-lg border border-rose-500/20 bg-rose-500/5 p-4 text-center text-rose-600 dark:text-rose-400"
                    >
                        <AlertCircle class="size-6 opacity-80" />
                        <span class="text-xs font-medium">Failed</span>
                    </div>
                </div>

                <!-- Generation Details -->
                <div class="flex min-w-0 flex-1 flex-col gap-3">
                    <div
                        class="flex flex-wrap items-start justify-between gap-2"
                    >
                        <p
                            class="text-base leading-snug font-medium break-words text-foreground"
                        >
                            {{ gen.prompt }}
                        </p>

                        <!-- Coloured Status Badge -->
                        <span
                            class="inline-flex shrink-0 items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-semibold capitalize"
                            :class="statusBadgeClasses(gen.status)"
                            :data-testid="`status-badge-${gen.id}`"
                        >
                            <Loader2
                                v-if="gen.status === 'processing'"
                                class="size-3 animate-spin"
                            />
                            <Clock
                                v-else-if="gen.status === 'queued'"
                                class="size-3"
                            />
                            <CheckCircle2
                                v-else-if="gen.status === 'succeeded'"
                                class="size-3"
                            />
                            <AlertCircle v-else class="size-3" />
                            {{ gen.status }}
                        </span>
                    </div>

                    <!-- Metadata pills: size, quality, timestamp -->
                    <div
                        class="flex flex-wrap items-center gap-2 text-xs text-muted-foreground"
                    >
                        <span
                            class="rounded-md border bg-muted/50 px-2 py-0.5 font-mono"
                        >
                            Size: {{ gen.size }}
                        </span>
                        <span
                            class="rounded-md border bg-muted/50 px-2 py-0.5 capitalize"
                        >
                            Quality: {{ gen.quality }}
                        </span>
                        <span
                            v-if="
                                gen.reference_urls &&
                                gen.reference_urls.length > 0
                            "
                            class="rounded-md border bg-muted/50 px-2 py-0.5"
                        >
                            {{ gen.reference_urls.length }} reference
                            {{
                                gen.reference_urls.length === 1
                                    ? 'image'
                                    : 'images'
                            }}
                        </span>
                        <span v-if="gen.created_at" class="ml-auto">
                            {{ formatTimestamp(gen.created_at) }}
                        </span>
                    </div>

                    <!-- Costs Grid: Estimated & Charged -->
                    <div
                        class="grid grid-cols-2 gap-3 rounded-lg border bg-muted/30 p-3 sm:max-w-md"
                    >
                        <div>
                            <span
                                class="block text-[11px] font-medium tracking-wide text-muted-foreground uppercase"
                            >
                                Estimated
                            </span>
                            <span
                                class="mt-0.5 block font-mono text-sm font-semibold text-foreground"
                                :data-testid="`estimated-cost-${gen.id}`"
                            >
                                {{ formatUsd(gen.estimated_cost, 6) }}
                            </span>
                        </div>

                        <div>
                            <span
                                class="block text-[11px] font-medium tracking-wide text-muted-foreground uppercase"
                            >
                                Charged
                            </span>
                            <span
                                class="mt-0.5 block font-mono text-sm font-semibold text-foreground"
                                :data-testid="`charged-cost-${gen.id}`"
                            >
                                {{ formatChargedCost(gen) }}
                            </span>
                        </div>
                    </div>

                    <!-- Error Message when failed -->
                    <div
                        v-if="gen.status === 'failed' && gen.error_message"
                        class="flex items-start gap-2 rounded-lg border border-rose-500/30 bg-rose-500/10 p-3 text-xs text-rose-800 dark:text-rose-200"
                        :data-testid="`error-message-${gen.id}`"
                    >
                        <AlertCircle class="mt-0.5 size-4 shrink-0" />
                        <span>{{ gen.error_message }}</span>
                    </div>
                </div>
            </article>
        </div>

        <!-- Pagination -->
        <div
            v-if="pagination && pagination.last_page > 1"
            class="flex flex-wrap items-center justify-between gap-4 border-t pt-4"
            data-testid="pagination"
        >
            <p class="text-xs text-muted-foreground">
                Showing {{ pagination.from ?? 0 }} to
                {{ pagination.to ?? 0 }} of {{ pagination.total }} generations
            </p>

            <div class="flex items-center gap-2">
                <Button
                    v-if="pagination.prev_page_url"
                    as-child
                    variant="outline"
                    size="sm"
                >
                    <Link :href="pagination.prev_page_url" preserve-scroll>
                        Previous
                    </Link>
                </Button>
                <Button v-else variant="outline" size="sm" disabled>
                    Previous
                </Button>

                <span class="px-2 text-xs text-muted-foreground">
                    Page {{ pagination.current_page }} of
                    {{ pagination.last_page }}
                </span>

                <Button
                    v-if="pagination.next_page_url"
                    as-child
                    variant="outline"
                    size="sm"
                >
                    <Link :href="pagination.next_page_url" preserve-scroll>
                        Next
                    </Link>
                </Button>
                <Button v-else variant="outline" size="sm" disabled>
                    Next
                </Button>
            </div>
        </div>
    </div>
</template>
