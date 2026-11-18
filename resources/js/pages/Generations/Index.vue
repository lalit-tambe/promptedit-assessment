<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Sparkles } from '@lucide/vue';
import { Button } from '@/components/ui/button';

type GenerationItem = {
    id: number;
    prompt: string;
    size: string;
    quality: string;
    status: 'queued' | 'processing' | 'succeeded' | 'failed';
    estimated_cost: string;
    charged_cost: string | null;
    image_url: string | null;
    error_message: string | null;
    created_at: string;
};

defineProps<{
    generations: GenerationItem[];
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
</script>

<template>
    <Head title="Generation History" />

    <div class="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6 p-4 sm:p-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">
                    Generation History
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Your generated images and their costs.
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
            v-if="generations.length === 0"
            class="rounded-xl border p-8 text-center text-sm text-muted-foreground"
        >
            No generations yet.
        </div>

        <div v-else class="grid gap-4">
            <div
                v-for="gen in generations"
                :key="gen.id"
                class="rounded-xl border bg-card p-4 shadow-xs"
            >
                <div class="flex items-center justify-between gap-2">
                    <p class="font-medium">{{ gen.prompt }}</p>
                    <span class="rounded bg-muted px-2 py-0.5 text-xs uppercase">
                        {{ gen.status }}
                    </span>
                </div>
            </div>
        </div>
    </div>
</template>
