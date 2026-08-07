// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * What the palette fetches before the viewer has picked anything (#1257).
 *
 * The palette is the one navigation path with no hover to trigger on, so it
 * predicts instead: whichever row `Enter` would run is fetched once the
 * arrowing settles. The seam is the speculative request itself — which row it
 * names, how many of them there are, and that it carries the sidebar's cache
 * contract — never how long Inertia then holds the entry, which is the
 * framework's to test.
 */
vi.mock('@inertiajs/vue3', async () => {
    const { inertiaDouble } = await import('./CommandPalette.doubles');

    return inertiaDouble();
});

vi.mock('@lucide/vue', async () => {
    const { lucideDouble } = await import('./CommandPalette.stubs');

    return lucideDouble();
});

vi.mock('reka-ui', async () => {
    const { rekaDouble } = await import('./CommandPalette.stubs');

    return rekaDouble();
});

vi.mock(
    '@/actions/App/Http/Controllers/Channels/ChannelController',
    async () => {
        const { channelActionDouble } =
            await import('./CommandPalette.doubles');

        return channelActionDouble();
    },
);

vi.mock(
    '@/actions/App/Http/Controllers/Channels/SearchController',
    async () => {
        const { searchActionDouble } = await import('./CommandPalette.doubles');

        return searchActionDouble();
    },
);

vi.mock('@/components/PresenceDot.vue', async () => {
    const { presenceDotDouble } = await import('./CommandPalette.stubs');

    return presenceDotDouble();
});

vi.mock('@/components/SafeHtml.vue', async () => {
    const { safeHtmlDouble } = await import('./CommandPalette.stubs');

    return safeHtmlDouble();
});

vi.mock('@/components/ui/button', async () => {
    const { buttonDouble } = await import('./CommandPalette.stubs');

    return buttonDouble();
});

vi.mock('@/components/ui/command', async () => {
    const { commandDouble } = await import('./CommandPalette.stubs');

    return commandDouble();
});

vi.mock('@/components/ui/dialog', async () => {
    const { dialogDouble } = await import('./CommandPalette.stubs');

    return dialogDouble();
});

vi.mock('@/components/ui/sidebar', async () => {
    const { sidebarDouble } = await import('./CommandPalette.doubles');

    return sidebarDouble();
});

vi.mock('@/composables/useTeamPresence', async () => {
    const { teamPresenceDouble } = await import('./CommandPalette.doubles');

    return teamPresenceDouble();
});

vi.mock('@/composables/useIsMobile', async () => {
    const { isMobileDouble } = await import('./CommandPalette.doubles');

    return isMobileDouble();
});

vi.mock('@/composables/useMessageSearch', async () => {
    const { messageSearchDouble } = await import('./CommandPalette.doubles');

    return messageSearchDouble();
});

vi.mock('@/composables/useOpenDirectMessage', async () => {
    const { openDirectMessageDouble } =
        await import('./CommandPalette.doubles');

    return openDirectMessageDouble();
});

vi.mock('@/lib/pinUrl', async () => {
    const { pinUrlDouble } = await import('./CommandPalette.doubles');

    return pinUrlDouble();
});

import { channelCacheTag, predictionDelay } from '@/lib/prefetch';
import {
    channel,
    mountSwitcher,
    person,
    resetDoubles,
    router,
    unmountAll,
} from './CommandPalette.doubles';
import { highlighter } from './CommandPalette.stubs';
import CommandPalette from './CommandPalette.vue';

const DESIGN = channel({ id: 'c-design', name: 'design', slug: 'design' });
const RANDOM = channel({ id: 'c-random', name: 'random', slug: 'random' });

function mount() {
    return mountSwitcher(CommandPalette, {
        channels: [DESIGN, RANDOM],
        members: [person()],
    });
}

/** Land the keyboard on a row, the way reka-ui reports it. */
function highlight(value: unknown): void {
    highlighter.settle?.(value === undefined ? undefined : { value });
}

/** Let the prediction window elapse without waiting out real time. */
function settle(): void {
    vi.advanceTimersByTime(predictionDelay());
}

/** The URLs speculatively fetched so far, in the order they were asked for. */
function fetched(): string[] {
    return router.prefetch.mock.calls.map((call) => call[0] as string);
}

describe('the palette predicting where Enter would go', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        resetDoubles();
        highlighter.settle = null;
    });

    afterEach(() => {
        unmountAll();
        vi.useRealTimers();
    });

    it('fetches the channel the highlight settled on', () => {
        mount();

        highlight('channel:c-design');
        settle();

        expect(fetched()).toEqual(['/t/acme/c/design']);
    });

    /**
     * The acceptance criterion the whole debounce exists for: arrowing through
     * ten rows must cost one request, not ten.
     */
    it('spends one request on a burst of arrowing, for the row it stopped on', () => {
        mount();

        highlight('channel:c-design');
        highlight('channel:c-random');
        highlight('channel:c-design');
        highlight('channel:c-random');
        settle();

        expect(fetched()).toEqual(['/t/acme/c/random']);
    });

    it('waits for the selection to settle before fetching anything', () => {
        mount();

        highlight('channel:c-design');
        vi.advanceTimersByTime(predictionDelay() - 1);

        expect(router.prefetch).not.toHaveBeenCalled();
    });

    it('files the entry under the channel it holds', () => {
        mount();

        highlight('channel:c-design');
        settle();

        expect(router.prefetch.mock.calls[0][2]).toEqual({
            cacheTags: [channelCacheTag('c-design')],
        });
    });

    /**
     * `only` is part of the prefetch cache key, so a prediction carrying one
     * could never be claimed by the `router.visit` that picking the row fires.
     */
    it('carries no `only`, so the pick that follows can claim the entry', () => {
        mount();

        highlight('channel:c-design');
        settle();

        expect(router.prefetch.mock.calls[0][1]).not.toHaveProperty('only');
    });

    /** A person opens through a POST, which cannot be prefetched at all. */
    it('predicts nothing for a person', () => {
        mount();

        highlight('person:p1');
        settle();

        expect(router.prefetch).not.toHaveBeenCalled();
    });

    it('predicts nothing for a command, which has no URL to fetch', () => {
        mount();

        highlight('command:create-channel');
        settle();

        expect(router.prefetch).not.toHaveBeenCalled();
    });

    /**
     * A message result carries no channel id, so its entry could never be
     * flushed by the fleet — an untagged entry is worse than no entry.
     */
    it('predicts nothing for a message result', () => {
        mount();

        highlight('message:m1');
        settle();

        expect(router.prefetch).not.toHaveBeenCalled();
    });

    it('predicts nothing for a channel that is no longer in the list', () => {
        mount();

        highlight('channel:c-gone');
        settle();

        expect(router.prefetch).not.toHaveBeenCalled();
    });

    /**
     * Arrowing off a channel and onto a verb must not leave the channel's
     * request in flight behind it.
     */
    it('drops a pending prediction when the keyboard leaves the channel', () => {
        mount();

        highlight('channel:c-design');
        highlight('command:create-channel');
        settle();

        expect(router.prefetch).not.toHaveBeenCalled();
    });

    it('predicts nothing when the highlight is cleared altogether', () => {
        mount();

        highlight(undefined);
        settle();

        expect(router.prefetch).not.toHaveBeenCalled();
    });
});
