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
    '56': { dot: 'size-3.5 ring-[3px]', awayBorder: 'border-2' },
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
        /**
         * The person is in do-not-disturb. A connected dot swaps for the
         * crescent badge — filled stone when active, the hollow away ring with
         * a stone crescent when away — so both signals survive in one badge.
         * Ignored for someone offline, whose muted disc stays as it is.
         */
        isDnd?: boolean;
    }>(),
    { surfaceClass: 'bg-background', size: undefined, isDnd: false },
);

/** The geometry the badge owns; an inline dot leaves it all to the caller. */
const badgeClass = computed(() =>
    props.size
        ? ['absolute right-0 bottom-0 z-10', BADGE_GEOMETRY[props.size].dot]
        : null,
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
        const border = props.size
            ? BADGE_GEOMETRY[props.size].awayBorder
            : 'border-2';

        return [
            border,
            'border-muted-foreground',
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
        :class="cn('rounded-full', badgeClass, stateClass)"
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
