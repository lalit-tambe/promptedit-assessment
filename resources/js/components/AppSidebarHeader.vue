<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Wallet } from '@lucide/vue';
import { computed } from 'vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { formatUsd } from '@/lib/money';
import type { BreadcrumbItem } from '@/types';

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);

const page = usePage();
const formattedBalance = computed(() => {
    const balance = page.props.auth?.balance;

    if (typeof balance !== 'string' || balance.trim() === '') {
        return null;
    }

    return formatUsd(balance, 2);
});
</script>

<template>
    <header
        class="flex h-16 shrink-0 items-center justify-between gap-2 border-b border-sidebar-border/70 px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4"
    >
        <div class="flex items-center gap-2">
            <SidebarTrigger class="-ml-1" />
            <template v-if="breadcrumbs && breadcrumbs.length > 0">
                <Breadcrumbs :breadcrumbs="breadcrumbs" />
            </template>
        </div>

        <Link
            v-if="formattedBalance !== null"
            href="/generate"
            class="inline-flex items-center gap-1.5 rounded-md border bg-muted/40 px-2.5 py-1 text-xs font-medium text-foreground transition-colors hover:bg-muted"
            data-testid="nav-credit-balance"
        >
            <Wallet class="size-3.5 text-muted-foreground" />
            <span class="text-muted-foreground">Balance:</span>
            <span class="font-mono font-semibold">{{ formattedBalance }}</span>
        </Link>
    </header>
</template>
