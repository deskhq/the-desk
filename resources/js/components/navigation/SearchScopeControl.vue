<script setup lang="ts">
import { Button } from '@/components/ui/button';

/**
 * The Search panel's workspace-scope switch: two halves of one control rather
 * than the page's inline pills, so a long workspace name cannot push the second
 * option out of the 300px column.
 *
 * Purely presentational — the panel above owns the scope (the URL does, really)
 * and decides what widening or narrowing does to the other facets.
 */
defineProps<{
    /** The scope the URL currently carries. */
    scope: string;
    /** The current workspace's name, or null before one is resolved. */
    teamName: string | null;
}>();

const emit = defineEmits<{
    scope: [next: 'team' | 'all'];
}>();
</script>

<template>
    <div
        class="grid grid-cols-2 gap-0.5 rounded-full bg-sidebar-accent p-0.5"
        role="group"
        :aria-label="$t('Search scope')"
        data-test="scope-control"
    >
        <Button
            variant="segmented"
            size="none"
            type="button"
            class="h-6 min-w-0 rounded-full px-2 text-[11.5px] font-medium max-md:h-9 max-md:text-[13px]"
            :aria-pressed="scope === 'team'"
            data-test="scope-team"
            @click="emit('scope', 'team')"
        >
            <span class="truncate">{{ teamName ?? $t('This workspace') }}</span>
        </Button>
        <Button
            variant="segmented"
            size="none"
            type="button"
            class="h-6 min-w-0 rounded-full px-2 text-[11.5px] font-medium max-md:h-9 max-md:text-[13px]"
            :aria-pressed="scope === 'all'"
            data-test="scope-all"
            @click="emit('scope', 'all')"
        >
            <span class="truncate">{{ $t('All workspaces') }}</span>
        </Button>
    </div>
</template>
