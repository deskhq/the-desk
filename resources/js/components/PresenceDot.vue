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
        /**
         * The person is in do-not-disturb. A connected dot swaps for the
         * crescent badge — filled stone when active, the hollow away ring with
         * a stone crescent when away — so both signals survive in one badge.
         * Ignored for someone offline, whose muted disc stays as it is.
         */
        isDnd?: boolean;
    }>(),
    { surfaceClass: 'bg-background', isDnd: false },
);

/** Whether the crescent badge replaces the plain dot. */
const showsCrescent = computed(
    () => props.isDnd && props.presence !== 'offline',
);

/**
 * The three-state vocabulary, at every size the dot is drawn.
 *
 * Away keeps the dot's footprint and hollows it — a ring in the neutral stone
 * over the surface behind — so a roster still reads at a glance without
 * introducing a second colour. Offline stays a muted disc. In do-not-disturb
 * the connected states keep their geometry but carry the crescent: active
 * trades its green fill for the stone disc the glyph sits on, away keeps its
 * hollow ring.
 */
const stateClass = computed(() => {
    if (props.presence === 'active') {
        return showsCrescent.value
            ? 'flex items-center justify-center bg-muted-foreground'
            : 'bg-emerald-500';
    }

    if (props.presence === 'away') {
        return [
            'border-2 border-muted-foreground',
            props.surfaceClass,
            showsCrescent.value ? 'flex items-center justify-center' : '',
        ];
    }

    return 'bg-muted-foreground/50';
});

/** Crescent colour: light-on-stone when active, stone-on-surface when away. */
const crescentClass = computed(() =>
    props.presence === 'active' ? 'fill-background' : 'fill-muted-foreground',
);
</script>

<template>
    <span
        :data-presence="presence"
        :data-dnd="showsCrescent ? 'true' : undefined"
        aria-hidden="true"
        :class="cn('rounded-full', stateClass)"
    >
        <svg
            v-if="showsCrescent"
            viewBox="0 0 24 24"
            class="size-full scale-[0.62]"
            :class="crescentClass"
        >
            <path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9z" />
        </svg>
    </span>
</template>
