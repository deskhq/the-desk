<script setup lang="ts">
/**
 * PROTOTYPE — throwaway (#1244).
 *
 * Four variants of *what a navigation looks like while it is in flight*,
 * switchable on the live channel route via `?variant=A|B|C|D`. Mounted from
 * `Channels/Show.vue` behind a dev-only guard; nothing here ships.
 *
 * The overlay pretends to be navigating to the next channel in the sidebar, and
 * paints that channel's chrome from the shared shell props the client already
 * holds — the same derivation an Inertia `instant` visit's `pageProps` callback
 * would perform ({@see buildSyntheticMasthead}). So what you are judging is the
 * real fidelity ceiling, not a mockup of it.
 *
 * Hit "replay" (or press `r`) to run the in-flight state for its duration and
 * watch it settle back to the real page. `←` / `→` cycle variants.
 */
import { usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { pinUrl } from '@/lib/pinUrl';
import type { Channel, RosterMember } from '@/types';
import InFlightSwitcher from './InFlightSwitcher.vue';
import InFlightVariantA from './InFlightVariantA.vue';
import InFlightVariantB from './InFlightVariantB.vue';
import InFlightVariantC from './InFlightVariantC.vue';
import InFlightVariantD from './InFlightVariantD.vue';
import { buildSyntheticMasthead } from './syntheticProps';

const props = defineProps<{
    /** The channel actually open, so the pretend target is a different one. */
    currentSlug: string;
}>();

/**
 * How long the in-flight state is held. The map's miss path is ~120ms server
 * plus a 76-142ms edge floor, so ~250ms is the honest figure — but a state you
 * cannot see is a state you cannot judge, so the default is slowed down and the
 * realistic duration is one keypress away.
 */
const HOLD_MS = 1200;
const REALISTIC_MS = 250;

const VARIANTS = [
    { key: 'A', name: 'hold (nothing swaps)', component: InFlightVariantA },
    {
        key: 'B',
        name: 'instant chrome, empty stage',
        component: InFlightVariantB,
    },
    {
        key: 'C',
        name: 'instant chrome, ghost timeline',
        component: InFlightVariantC,
    },
    {
        key: 'D',
        name: 'crossfade (old messages hold)',
        component: InFlightVariantD,
    },
];

const page = usePage();

const variant = ref(readVariantFromUrl());
const holding = ref(false);

let timer: ReturnType<typeof setTimeout> | null = null;

function readVariantFromUrl(): string {
    const value = new URL(window.location.href).searchParams
        .get('variant')
        ?.toUpperCase();

    return VARIANTS.some((v) => v.key === value) ? (value as string) : 'A';
}

/** The channel this pretends to be navigating to: the next one in the sidebar. */
const target = computed<Channel | null>(() => {
    const channels = (page.props.channels ?? []) as Channel[];

    return (
        channels.find((channel) => channel.slug !== props.currentSlug) ??
        channels[0] ??
        null
    );
});

const synthetic = computed(() =>
    target.value
        ? buildSyntheticMasthead(
              target.value,
              // Typed `PersonRef[]` on the client, but the server ships the full
              // `UserData` — which is exactly why the facepile can paint.
              (page.props.teamMembers ?? []) as unknown as RosterMember[],
          )
        : null,
);

const active = computed(
    () => VARIANTS.find((v) => v.key === variant.value) ?? VARIANTS[0],
);

function replay(duration = HOLD_MS): void {
    if (timer !== null) {
        clearTimeout(timer);
    }

    holding.value = true;
    timer = setTimeout(() => {
        holding.value = false;
        timer = null;
    }, duration);
}

function select(key: string): void {
    variant.value = key;

    const url = new URL(window.location.href);
    url.searchParams.set('variant', key);
    pinUrl(url.pathname + url.search + url.hash);

    replay();
}

function onKeydown(event: KeyboardEvent): void {
    const element = event.target as HTMLElement | null;

    if (
        element &&
        (element.tagName === 'INPUT' ||
            element.tagName === 'TEXTAREA' ||
            element.isContentEditable)
    ) {
        return;
    }

    // Shift+R runs the in-flight state at its real ~250ms, which is the length
    // the decision is actually about.
    if (event.key === 'R') {
        event.preventDefault();
        replay(REALISTIC_MS);
    }
}

onMounted(() => {
    window.addEventListener('keydown', onKeydown);
    replay();
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);

    if (timer !== null) {
        clearTimeout(timer);
    }
});
</script>

<template>
    <component
        :is="active.component"
        v-if="holding && synthetic"
        :synthetic="synthetic"
    />

    <InFlightSwitcher
        :variants="VARIANTS"
        :current="variant"
        :holding="holding"
        @select="select"
        @replay="replay()"
    />
</template>
