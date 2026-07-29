<script setup lang="ts">
import { NAV_DESTINATION_LABELS } from '@/components/navigation/destinations';
import type { NavDestination } from '@/composables/useNavPanel';

/**
 * The frame a non-conversation destination renders into: a heading naming the
 * destination, whatever action that destination hangs beside it, an optional
 * toolbar pinned under the heading, and its own scrolling body. Threads (#939),
 * reminders (#940) and search (#941) share it so their geometry matches and the
 * rail beside them never shifts as the panel swaps.
 *
 * "You" (#942) is deliberately not one of them: its identity header — the
 * portrait, the name and the workspace line — *is* its heading, so it renders
 * its own root rather than this one.
 */
withDefaults(
    defineProps<{
        destination: NavDestination;
        /**
         * Padding for the scrolling body, for a destination whose own rows carry
         * the gutter (a card list sits tighter than prose does).
         */
        bodyClass?: string;
    }>(),
    { bodyClass: 'p-3.5' },
);
</script>

<template>
    <div
        :data-test="`destination-panel-${destination}`"
        class="flex min-h-0 flex-1 flex-col"
    >
        <div
            class="flex h-11 shrink-0 items-center gap-2 border-b border-sidebar-border px-3.5"
        >
            <h2
                class="min-w-0 flex-1 truncate text-[15px] font-semibold text-sidebar-foreground"
            >
                {{ $t(NAV_DESTINATION_LABELS[destination]) }}
            </h2>
            <slot name="action" />
        </div>
        <slot name="toolbar" />
        <div
            class="flex min-h-0 flex-1 flex-col overflow-auto"
            :class="bodyClass"
        >
            <slot />
        </div>
    </div>
</template>
