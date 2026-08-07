// @vitest-environment jsdom
import { config } from '@inertiajs/vue3';
import type * as InertiaVue from '@inertiajs/vue3';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { prefetch } = vi.hoisted(() => ({ prefetch: vi.fn() }));

// Only the router is stood in for: `config` stays the real one, so the delay
// this module publishes is genuinely the delay Inertia's own links wait out.
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof InertiaVue>()),
    router: { prefetch },
}));

import {
    adjacentChannels,
    channelCacheTag,
    predictionDelay,
    prefetchChannel,
    prefetchTrigger,
} from '@/lib/prefetch';
import type { Channel } from '@/types/channels';

/** A sidebar row, carrying only what the prediction reads off it. */
function channel(slug: string): Channel {
    return { id: `id-${slug}`, slug, name: slug } as Channel;
}

/** The roster in sidebar order, which is the order the walk steps through. */
const ROSTER = ['general', 'design', 'random'].map(channel);

/** The slugs a prediction would fetch, in the order it fetches them. */
function predicted(
    channels: readonly Channel[],
    activeSlug: string | null,
): string[] {
    return adjacentChannels(channels, activeSlug).map((found) => found.slug);
}

describe('the prefetch cache tag', () => {
    it('names the channel it caches, so an arrival can flush exactly that entry', () => {
        expect(channelCacheTag('9f1c-abcd')).toBe('channel:9f1c-abcd');
    });

    it('gives two channels two different tags', () => {
        expect(channelCacheTag('one')).not.toBe(channelCacheTag('two'));
    });
});

describe('the prefetch trigger', () => {
    it('buys the travel-to-click time on a device that can hover', () => {
        expect(prefetchTrigger(true)).toBe('hover');
    });

    it('falls back to the mousedown-to-mouseup window where nothing hovers', () => {
        expect(prefetchTrigger(false)).toBe('click');
    });

    /**
     * `['hover', 'click']` renders as `if hover → hoverEvents; else if click →
     * clickEvents`, so the click half is silently dropped. The mode is chosen
     * once, and it is never an array.
     */
    it('never asks for both modes at once', () => {
        expect(prefetchTrigger(true)).not.toBeInstanceOf(Array);
        expect(prefetchTrigger(false)).not.toBeInstanceOf(Array);
    });
});

describe('the channels a walk can reach in one step', () => {
    it('is the next and the previous row, and nothing else', () => {
        expect(predicted(ROSTER, 'design')).toEqual(['random', 'general']);
    });

    /**
     * The jump wraps at either end, so the ends are neighbours rather than
     * dead stops — and the prediction has to agree with the jump or it fetches
     * a channel ⌘↑ / ⌘↓ never lands on.
     */
    it('wraps at the top of the list, as the jump itself does', () => {
        expect(predicted(ROSTER, 'general')).toEqual(['design', 'random']);
    });

    it('wraps at the bottom of the list', () => {
        expect(predicted(ROSTER, 'random')).toEqual(['general', 'design']);
    });

    /** Both neighbours are the same row, so the cost is one request, not two. */
    it('spends one request in a two-channel workspace', () => {
        expect(predicted(ROSTER.slice(0, 2), 'general')).toEqual(['design']);
    });

    /** Both neighbours are the channel already on screen. */
    it('spends nothing in a one-channel workspace', () => {
        expect(predicted(ROSTER.slice(0, 1), 'general')).toEqual([]);
    });

    it('spends nothing on an empty roster', () => {
        expect(predicted([], null)).toEqual([]);
    });

    /**
     * An archived or just-deleted slug still leaves the walk somewhere to go —
     * `adjacentSlug` falls back to either end — so the prediction stays two
     * real URLs rather than a guess at a channel that is gone.
     */
    it('falls back to the ends for a channel that has left the list', () => {
        expect(predicted(ROSTER, 'archived')).toEqual(['general', 'random']);
    });

    it('never predicts the channel already on screen', () => {
        expect(predicted(ROSTER, 'design')).not.toContain('design');
    });
});

describe('a speculative fetch of a channel', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('files the entry under the channel it holds, so an arrival can flush it', () => {
        prefetchChannel('/t/acme/c/design', 'c1');

        expect(prefetch).toHaveBeenCalledWith(
            '/t/acme/c/design',
            expect.anything(),
            { cacheTags: [channelCacheTag('c1')] },
        );
    });

    /**
     * `only` is part of Inertia's prefetch cache key, so a prediction carrying
     * one could never be claimed by the bare `router.visit` that follows it.
     * Nothing fails loudly when that drifts, which is why it is asserted here.
     */
    it('carries no `only`, the same as the sidebar rows it must match', () => {
        prefetchChannel('/t/acme/c/design', 'c1');

        expect(prefetch.mock.calls[0][1]).not.toHaveProperty('only');
    });

    /**
     * `router.prefetch` falls back to `config.get('prefetch.cacheFor')`, which
     * is the same value a `<Link>` falls back to. Naming it here would be a
     * second place for the lifetime to drift from the sidebar's.
     */
    it('names no lifetime, inheriting the one the links inherit', () => {
        prefetchChannel('/t/acme/c/design', 'c1');

        expect(prefetch.mock.calls[0][2]).not.toHaveProperty('cacheFor');
    });
});

describe('the delay a prediction waits out', () => {
    it('is the one Inertia already makes its own hover links wait', () => {
        expect(predictionDelay()).toBe(config.get('prefetch.hoverDelay'));
    });
});
