<script setup lang="ts">
import { computed } from 'vue';
import type { RenderedPresence } from '@/lib/presence';
import { cn } from '@/lib/utils';

/**
 * The corner-badge geometry, keyed by the avatar diameter (in px) the dot
 * badges. Each entry owns the dot's diameter, its ring width, and the border
 * width of the away state's hollow ring, so no call site re-specifies them.
 * The smallest avatar thins both rings, keeping the hollow centre readable.
 */
const BADGE_GEOMETRY = {
    '18': { dot: 'size-1.5 ring-[1.5px]', awayBorder: 'border-[1.5px]' },
    '24': { dot: 'size-2 ring-2', awayBorder: 'border-2' },
    '28': { dot: 'size-2.5 ring-2', awayBorder: 'border-2' },
    '30': { dot: 'size-2.5 ring-2', awayBorder: 'border-2' },
    '36': { dot: 'size-2.5 ring-2', awayBorder: 'border-2' },
    '42': { dot: 'size-2.75 ring-2', awayBorder: 'border-2' },
    '48': { dot: 'size-3 ring-[2.5px]', awayBorder: 'border-2' },
} as const;

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
         * The diameter (in px) of the avatar this dot badges. When given, the
         * dot renders as a corner badge: tucked inside the avatar's
         * bottom-right corner, sized and ringed proportionally, and raised
         * above the later siblings of an overlapping stack. The caller only
         * supplies the ring's colour (`ring-card`, `ring-sidebar`, …) via
         * `class`, matching the surface behind the avatar. Omit for an inline
         * dot whose geometry the caller owns.
         */
        size?: keyof typeof BADGE_GEOMETRY;
    }>(),
    { surfaceClass: 'bg-background', size: undefined },
);

/** The geometry the badge owns; an inline dot leaves it all to the caller. */
const badgeClass = computed(() =>
    props.size
        ? ['absolute right-0 bottom-0 z-10', BADGE_GEOMETRY[props.size].dot]
        : null,
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

    if (props.presence === 'away') {
        const border = props.size
            ? BADGE_GEOMETRY[props.size].awayBorder
            : 'border-2';

        return [border, 'border-muted-foreground', props.surfaceClass];
    }

    return 'bg-muted-foreground/50';
});
</script>

<template>
    <span
        :data-presence="presence"
        aria-hidden="true"
        :class="cn('rounded-full', badgeClass, stateClass)"
    />
</template>
