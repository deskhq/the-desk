<script setup lang="ts">
import { NAV_DESTINATION_LABELS } from '@/components/navigation/destinations';
import type { NavDestination } from '@/composables/useNavPanel';

/**
 * The frame a non-conversation destination renders into: a heading naming the
 * destination, and the body its own child issue fills in (#939 threads, #940
 * reminders, #941 search, #942 you). The frame lives here so all four share one
 * geometry and the rail beside them never shifts as the panel swaps.
 */
defineProps<{ destination: NavDestination }>();
</script>

<template>
    <div
        :data-test="`destination-panel-${destination}`"
        class="flex min-h-0 flex-1 flex-col"
    >
        <div
            class="flex h-11 shrink-0 items-center border-b border-sidebar-border px-3.5"
        >
            <h2
                class="truncate text-[15px] font-semibold text-sidebar-foreground"
            >
                {{ $t(NAV_DESTINATION_LABELS[destination]) }}
            </h2>
        </div>
        <div class="flex min-h-0 flex-1 flex-col overflow-auto p-3.5">
            <slot />
        </div>
    </div>
</template>
