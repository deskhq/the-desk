// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, defineComponent, h } from 'vue';

/**
 * Exercises the recently-deleted panel: the rows it lists (name, contents, the
 * two dates), the empty state, the restore POST, and the alert raised when the
 * server refuses a restore because the channel's name has been taken again.
 * Inertia's `useForm` is stubbed so the POST is observable without network I/O,
 * and `usePage` feeds the shared error bag the refusal arrives in.
 */
const post = vi.hoisted(() => vi.fn());
const pageErrors = vi.hoisted(() => ({ value: {} as Record<string, string> }));

vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent } = await import('vue');

    return {
        Head: defineComponent({
            name: 'HeadStub',
            setup: () => () => null,
        }),
        useForm: () => ({ post, processing: false }),
        usePage: () => ({
            props: {
                errors: pageErrors.value,
                channelRestoreWindowDays: 30,
            },
        }),
    };
});

vi.mock('@/routes/teams', () => ({
    edit: () => '/settings/teams/acme',
    index: () => '/settings/teams',
}));

vi.mock('@/routes/teams/deleted-channels', () => ({
    index: () => '/settings/teams/acme/deleted-channels',
    restore: ({ channel }: { channel: string }) => ({
        url: `/settings/teams/acme/deleted-channels/${channel}/restore`,
    }),
}));

vi.mock('@/composables/useTimezone', async () => {
    const { ref } = await import('vue');

    return { useTimezone: () => ({ timezone: ref('UTC') }) };
});

import DeletedChannels from './DeletedChannels.vue';

type DeletedChannel = App.Data.DeletedChannelData;

function deletedChannel(
    overrides: Partial<DeletedChannel> = {},
): DeletedChannel {
    return {
        id: 'chan-1',
        name: 'Roadmap',
        slug: 'roadmap',
        deletedAt: '2026-07-01T09:00:00.000Z',
        purgeAt: '2026-07-31T09:00:00.000Z',
        summary: { messageCount: 3412, fileCount: 88, memberCount: 12 },
        ...overrides,
    };
}

let active: Array<{ app: App; container: HTMLElement }> = [];

function mount(channels: DeletedChannel[]): HTMLElement {
    const container = document.createElement('div');
    document.body.appendChild(container);

    const app = createApp(
        defineComponent({
            setup() {
                return () =>
                    h(DeletedChannels, {
                        team: {
                            id: 'team-1',
                            name: 'Acme',
                            slug: 'acme',
                            isPersonal: false,
                            role: 'owner',
                            membersCount: 1,
                        },
                        channels,
                    });
            },
        }),
    );
    app.config.globalProperties.$t = (
        key: string,
        replacements: Record<string, string | number> = {},
    ) =>
        Object.entries(replacements).reduce(
            (line, [token, value]) =>
                line.replaceAll(`:${token}`, String(value)),
            key,
        );
    app.mount(container);
    active.push({ app, container });

    return container;
}

afterEach(() => {
    for (const { app, container } of active) {
        app.unmount();
        container.remove();
    }

    active = [];
    post.mockClear();
    pageErrors.value = {};
});

describe('DeletedChannels', () => {
    it('lists a deleted channel with what it holds and when it will be purged', () => {
        const container = mount([deletedChannel()]);

        const row = container.querySelector(
            '[data-test="deleted-channel-row-roadmap"]',
        );

        expect(row?.textContent).toContain('#Roadmap');
        expect(row?.textContent).toContain('3,412 messages · 88 files');
        expect(
            container.querySelector(
                '[data-test="deleted-channel-purge-roadmap"]',
            )?.textContent,
        ).toContain('Purged');
    });

    it('says so when nothing has been deleted', () => {
        const container = mount([]);

        expect(
            container.querySelector('[data-test="deleted-channels-empty"]'),
        ).not.toBeNull();
        expect(
            container.querySelector('[data-test="deleted-channels-list"]'),
        ).toBeNull();
    });

    it('posts to the restore route for the channel whose button was pressed', () => {
        const container = mount([
            deletedChannel(),
            deletedChannel({ id: 'chan-2', name: 'Hiring', slug: 'hiring' }),
        ]);

        container
            .querySelector<HTMLButtonElement>(
                '[data-test="restore-channel-hiring"]',
            )!
            .click();

        expect(post).toHaveBeenCalledWith(
            '/settings/teams/acme/deleted-channels/chan-2/restore',
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('raises the server’s reason when a restore is refused', () => {
        pageErrors.value = {
            channel: 'A channel named #roadmap already exists.',
        };

        const container = mount([deletedChannel()]);

        expect(
            container.querySelector('[data-test="restore-channel-error"]')
                ?.textContent,
        ).toContain('already exists');
    });
});
