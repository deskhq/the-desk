<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { InertiaLinkProps } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';

defineProps<{
    /** Where the card leads. */
    href: NonNullable<InertiaLinkProps['href']>;
    /** The card's leading glyph. */
    icon: LucideIcon;
    /** What the destination is called. */
    title: string;
    /** The one-line summary under the title. */
    description: string;
}>();
</script>

<template>
    <Link
        :href="href"
        class="flex items-center gap-3 rounded-xl border border-border bg-card p-4 transition-colors hover:border-brass-border"
    >
        <component :is="icon" class="h-4 w-4 shrink-0 text-brass" />
        <div class="min-w-0">
            <div class="text-sm font-semibold">
                {{ title }}
            </div>
            <div class="truncate text-xs text-muted-foreground">
                {{ description }}
            </div>
        </div>
        <ChevronRight
            class="ml-auto h-3.5 w-3.5 shrink-0 text-muted-foreground"
        />
    </Link>
</template>
