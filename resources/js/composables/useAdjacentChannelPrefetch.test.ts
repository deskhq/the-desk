// @vitest-environment jsdom
import type * as InertiaVue from '@inertiajs/vue3';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, h, nextTick, reactive } from 'vue';

const { prefetch } = vi.hoisted(() => ({ prefetch: vi.fn() }));

const page = reactive<{ props: Record<string, unknown> }>({ props: {} });

// `config` stays real so the module under test keeps reading Inertia's own
// prefetch defaults; only the router and the page are stood in for.
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof InertiaVue>()),
    router: { prefetch },
    usePage: () => page,
}));

import { useAdjacentChannelPrefetch } from '@/composables/useAdjacentChannelPrefetch';
import { channelCacheTag } from '@/lib/prefetch';
import type { Channel } from '@/types/channels';

let app: App | null = null;

function channel(slug: string): Channel {
    return { id: `id-${slug}`, slug, name: slug } as Channel;
}

/** Seed the shared props the prediction reads, in sidebar order. */
function seed(overrides: Record<string, unknown> = {}): void {
    page.props = {
        currentTeam: { id: 't1', slug: 'acme' },
        channels: ['general', 'design', 'random'].map(channel),
        channel: { id: 'id-design', slug: 'design' },
        ...overrides,
    };
}

function boot(): void {
    app = createApp({
        setup: () => {
            useAdjacentChannelPrefetch();

            return () => h('div');
        },
    });
    app.mount(document.createElement('div'));
}

/** The URLs speculatively fetched so far, in the order they were asked for. */
function fetched(): string[] {
    return prefetch.mock.calls.map((call) => call[0] as string);
}

describe('useAdjacentChannelPrefetch', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        seed();
    });

    afterEach(() => {
        app?.unmount();
        app = null;
    });

    it('fetches the two channels the walk can reach, and no others', () => {
        boot();

        expect(fetched()).toEqual(['/t/acme/c/random', '/t/acme/c/general']);
    });

    /**
     * The cost that makes prediction safe here where `mount` was not: it is two
     * requests whether the workspace holds three channels or two hundred.
     */
    it('spends two requests regardless of how large the workspace is', () => {
        seed({
            channels: Array.from({ length: 200 }, (_, index) =>
                channel(`c${index}`),
            ),
            channel: { id: 'id-c100', slug: 'c100' },
        });

        boot();

        expect(prefetch).toHaveBeenCalledTimes(2);
    });

    it('files each entry under the channel it holds', () => {
        boot();

        expect(prefetch.mock.calls[0][2]).toEqual({
            cacheTags: [channelCacheTag('id-random')],
        });
    });

    it('predicts again when the viewer moves to another channel', async () => {
        boot();
        prefetch.mockClear();

        page.props.channel = { id: 'id-general', slug: 'general' };
        await nextTick();

        expect(fetched()).toEqual(['/t/acme/c/design', '/t/acme/c/random']);
    });

    /**
     * A star, a reorder, or a first-ever direct message moves who the
     * neighbours are without the active channel changing at all.
     */
    it('predicts again when the roster itself moves', async () => {
        boot();
        prefetch.mockClear();

        page.props.channels = ['design', 'random'].map(channel);
        await nextTick();

        expect(fetched()).toEqual(['/t/acme/c/random']);
    });

    /**
     * Settings and browse pages mount the same shell. There is no walk in
     * progress there, so predicting would spend two requests on a guess.
     */
    it('predicts nothing on a page with no channel open', () => {
        seed({ channel: undefined });

        boot();

        expect(prefetch).not.toHaveBeenCalled();
    });

    it('predicts nothing before a workspace is loaded', () => {
        seed({ currentTeam: undefined });

        boot();

        expect(prefetch).not.toHaveBeenCalled();
    });
});
