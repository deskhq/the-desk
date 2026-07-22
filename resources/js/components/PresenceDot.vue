<script setup lang="ts">
import { computed } from 'vue';
import type { RenderedPresence } from '@/lib/presence';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        /** How the person renders: connected and active, connected but idle, or gone. */
        presence: RenderedPresence;
        /**
         * Background class matching the surface the dot sits on (`bg-card`,
         * `bg-sidebar`, …). Only used by the away state, whose centre is the
         * surface showing through the ring — without it the avatar underneath
         * would show through the hole. Ignored otherwise.
         */
        surfaceClass?: string;
    }>(),
    { surfaceClass: 'bg-background' },
);

/**
 * The three-state vocabulary, at every size the dot is drawn.
 *
 * Away keeps the dot's footprint and hollows it — a ring in the neutral stone
 * over the surface behind — so a roster still reads at a glance without
 * introducing a second colour. Offline stays a muted disc.
 */
const stateClass = computed(() => {
    if (props.presence === 'active') {
        return 'bg-emerald-500';
    }

    return props.presence === 'away'
        ? ['border-2 border-muted-foreground', props.surfaceClass]
        : 'bg-muted-foreground/50';
});
</script>

<template>
    <span
        :data-presence="presence"
        aria-hidden="true"
        :class="cn('rounded-full', stateClass)"
    />
</template>
