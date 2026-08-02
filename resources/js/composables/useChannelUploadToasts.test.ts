// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, h, nextTick, reactive } from 'vue';

const { visit } = vi.hoisted(() => ({ visit: vi.fn() }));

const page = reactive<{ props: Record<string, unknown> }>({ props: {} });

vi.mock('@inertiajs/vue3', () => ({
    router: { visit },
    usePage: () => page,
}));

/** The options the wiring hands the reporter, captured instead of run. */
const reporter = vi.hoisted(() => ({
    options: null as unknown,
}));

vi.mock('@/composables/useUploadProgressToast', () => ({
    useUploadProgressToast: (options: unknown) => {
        reporter.options = options;
    },
}));

const { releaseChannelUploads } = vi.hoisted(() => ({
    releaseChannelUploads: vi.fn(),
}));

vi.mock('@/composables/useChannelUploads', async () => {
    const { ref } = await import('vue');

    return {
        channelUploads: ref([]),
        channelUploadKey: (teamSlug: string, channelSlug: string) =>
            `${teamSlug}/${channelSlug}`,
        releaseChannelUploads,
    };
});

import { useChannelUploadToasts } from '@/composables/useChannelUploadToasts';
import type { UploadProgressToastOptions } from '@/composables/useUploadProgressToast';
import type { Channel } from '@/types/channels';

let app: App | null = null;

function channel(overrides: Partial<Channel> = {}): Channel {
    return {
        name: 'design',
        slug: 'design',
        isDirect: false,
        ...overrides,
    } as Channel;
}

/** Seed the shared workspace props the wiring reads. */
function seed(overrides: Record<string, unknown> = {}): void {
    page.props = {
        currentTeam: { id: 't1', slug: 'acme' },
        channels: [channel()],
        ...overrides,
    };
}

function boot(): UploadProgressToastOptions {
    app = createApp({
        setup: () => {
            useChannelUploadToasts();

            return () => h('div');
        },
    });
    app.mount(document.createElement('div'));

    return reporter.options as UploadProgressToastOptions;
}

describe('useChannelUploadToasts', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        reporter.options = null;
        seed();
    });

    afterEach(() => {
        app?.unmount();
        app = null;
    });

    describe('naming the channel a batch is stuck in', () => {
        it('hashes a channel, so the toast reads as the sidebar does', () => {
            expect(boot().channelName('acme/design')).toBe('#design');
        });

        it('leaves a direct message its plain name', () => {
            seed({ channels: [channel({ name: 'Ada', isDirect: true })] });

            expect(boot().channelName('acme/design')).toBe('Ada');
        });

        it('names nothing for a channel that has left the sidebar', () => {
            expect(boot().channelName('acme/archived')).toBeNull();
        });
    });

    describe('the channel whose composer is on screen', () => {
        it('is the open one, keyed the way the tray files it', () => {
            seed({ channel: { slug: 'design' } });

            expect(boot().activeChannelKey()).toBe('acme/design');
        });

        it('is nothing on a page with no channel behind it', () => {
            expect(boot().activeChannelKey()).toBeNull();
        });
    });

    describe('the View action', () => {
        it('takes the viewer to the channel the batch is sitting in', () => {
            boot().openChannel('acme/design');

            expect(visit).toHaveBeenCalledOnce();
            expect(visit.mock.calls[0][0]).toContain('design');
        });

        it('goes nowhere for a channel the sidebar no longer has', () => {
            boot().openChannel('acme/archived');

            expect(visit).not.toHaveBeenCalled();
        });
    });

    it('drops the staged trays when the viewer leaves the workspace', async () => {
        // The trays belong to channels that are gone from the sidebar, so they
        // have no surface to come back to; dropping the registry frees them.
        boot();

        expect(releaseChannelUploads).not.toHaveBeenCalled();

        page.props = {
            ...page.props,
            currentTeam: { id: 't2', slug: 'other' },
        };
        await nextTick();

        expect(releaseChannelUploads).toHaveBeenCalledOnce();
    });
});
