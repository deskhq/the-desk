// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, defineComponent, h, reactive } from 'vue';
import type { Mention, Message, ThreadInboxItem } from '@/types';

const { get, post } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));

const page = reactive<{ url: string; props: Record<string, unknown> }>({
    url: '/t/acme/c/general?nav=threads',
    props: {},
});

vi.mock('@inertiajs/vue3', () => ({
    router: { get, post },
    usePage: () => page,
    Link: defineComponent({
        props: { href: { type: String, default: '' } },
        setup:
            (props, { slots }) =>
            () =>
                h('a', { href: props.href }, slots.default?.()),
    }),
    InfiniteScroll: defineComponent({
        setup:
            (_, { slots }) =>
            () =>
                h('div', slots.default?.()),
    }),
}));

vi.mock('@/components/SafeHtml.vue', () => ({
    default: defineComponent({
        props: { html: { type: String, default: '' } },
        setup: (props) => () => h('span', props.html),
    }),
}));

vi.mock('@/components/AvatarStack.vue', () => ({
    default: defineComponent({
        setup: () => () => h('span', { 'data-test': 'avatar-stack' }),
    }),
}));

vi.mock('@/components/ui/avatar', () => ({
    Avatar: defineComponent({
        setup:
            (_, { slots }) =>
            () =>
                h('span', slots.default?.()),
    }),
    AvatarImage: defineComponent({
        props: { src: { type: String, default: '' } },
        setup: (props) => () => h('img', { src: props.src }),
    }),
    AvatarFallback: defineComponent({
        setup:
            (_, { slots }) =>
            () =>
                h('span', slots.default?.()),
    }),
}));

import ThreadsPanel from './ThreadsPanel.vue';

const author: Mention = { id: 'u-1', name: 'Carol Danvers' };

function threadItem(overrides: Partial<ThreadInboxItem> = {}): ThreadInboxItem {
    const root: Message = {
        id: 'm-1',
        clientUuid: 'uuid-1',
        body: 'Shall we move Thursday?',
        type: 'standard',
        user: author,
        createdAt: '2026-07-20T14:02:00.000Z',
        editedAt: null,
        isDeleted: false,
        mentions: [],
        linkPreviews: [],
        reactions: [],
        attachments: [],
        poll: null,
        pin: null,
        replyTo: null,
        forwardedFrom: null,
        threadRootId: null,
        sentToChannel: false,
        threadReplyCount: 7,
        threadLastReplyAt: '2026-07-20T14:02:00.000Z',
        threadParticipants: [author],
        threadFollowed: true,
        threadUnread: true,
        threadUnreadReplyCount: 4,
        ...((overrides.root ?? {}) as Partial<Message>),
    } as Message;

    return {
        root,
        channelName: 'leadership',
        channelSlug: 'leadership',
        isDirectMessage: false,
        dmParticipant: null,
        ...overrides,
        root,
    };
}

let app: App | null = null;

beforeEach(() => {
    get.mockClear();
    post.mockClear();
    page.url = '/t/acme/c/general?nav=threads';
    page.props = {
        auth: { user: { id: 'u-9', name: 'Dana', timezone: 'UTC' } },
        currentTeam: { id: 't-1', name: 'Acme', slug: 'acme' },
        customEmojis: {},
        userGroups: [],
    };
});

afterEach(() => {
    app?.unmount();
    app = null;
    document.body.innerHTML = '';
});

function mountPanel(): HTMLElement {
    const host = document.createElement('div');
    document.body.append(host);

    app = createApp({ render: () => h(ThreadsPanel) });
    app.config.globalProperties.$t = (key: string) => key;
    app.mount(host);

    return host;
}

/** The prop set the panel gets once its own visit lands. */
function withInbox(items: ThreadInboxItem[], unreadThreadCount = items.length) {
    page.props.threads = {
        data: items,
        next_cursor: null,
        prev_cursor: null,
    };
    page.props.unreadThreadCount = unreadThreadCount;
}

describe('the inbox arriving', () => {
    it('pulls the inbox itself when the panel opens without props', () => {
        mountPanel();

        expect(get).toHaveBeenCalledTimes(1);
        expect(get).toHaveBeenCalledWith(
            '/t/acme/c/general?nav=threads',
            {},
            expect.objectContaining({
                only: ['threads', 'unreadThreadCount'],
                reset: ['threads'],
            }),
        );
    });

    it('pins the destination on the request even before the rail history write lands', () => {
        page.url = '/t/acme/c/general';

        mountPanel();

        expect(get).toHaveBeenCalledWith(
            '/t/acme/c/general?nav=threads',
            {},
            expect.anything(),
        );
    });

    it('leaves a deep link alone, whose props already rode along', () => {
        withInbox([threadItem()]);

        mountPanel();

        expect(get).not.toHaveBeenCalled();
    });

    it('stands in pulsing cards until the inbox lands', () => {
        const host = mountPanel();

        expect(
            host.querySelectorAll('[data-test="threads-skeleton-card"]').length,
        ).toBeGreaterThan(0);
        expect(host.querySelector('[data-test="threads-empty"]')).toBeNull();
    });
});

describe('a card', () => {
    it('reads as unread with the new-reply count, not the total', () => {
        withInbox([threadItem()]);

        const host = mountPanel();

        const card = host.querySelector('[data-test="thread-inbox-item"]')!;

        expect(card.getAttribute('href')).toBe(
            '/t/acme/c/leadership?thread=m-1',
        );
        expect(card.className).toContain('border-brass');
        // No catalog is loaded, so the key falls back to English with its
        // placeholder interpolated.
        expect(
            card.querySelector('[data-test="thread-unread-dot"]')?.textContent,
        ).toBe('4 new replies');
        expect(card.querySelector('[data-test="avatar-stack"]')).not.toBeNull();
    });

    it('drops to the total once the viewer is caught up', () => {
        withInbox(
            [
                threadItem({
                    root: {
                        threadUnread: false,
                        threadUnreadReplyCount: 0,
                    } as Message,
                }),
            ],
            0,
        );

        const host = mountPanel();

        const card = host.querySelector('[data-test="thread-inbox-item"]')!;

        expect(card.className).not.toContain('border-brass');
        expect(card.querySelector('[data-test="thread-unread-dot"]')).toBeNull();
        expect(card.textContent).toContain('7 replies');
        // The stack belongs to the unread treatment the design draws.
        expect(card.querySelector('[data-test="avatar-stack"]')).toBeNull();
    });

    it('names a DM by its counterpart, with no channel hash', () => {
        withInbox([
            threadItem({
                channelName: 'Carol Danvers',
                isDirectMessage: true,
                dmParticipant: author,
            }),
        ]);

        const host = mountPanel();

        const card = host.querySelector('[data-test="thread-inbox-item"]')!;

        expect(card.textContent).toContain('Carol Danvers');
        expect(card.textContent).not.toContain('#');
    });
});

describe('the filter pills', () => {
    it('marks the active pill and counts the unread ones', () => {
        withInbox([threadItem()], 2);

        const host = mountPanel();

        expect(
            host
                .querySelector('[data-test="threads-filter-unread"]')
                ?.getAttribute('aria-pressed'),
        ).toBe('true');
        expect(
            host
                .querySelector('[data-test="threads-filter-all"]')
                ?.getAttribute('aria-pressed'),
        ).toBe('false');
        expect(
            host.querySelector('[data-test="threads-unread-count"]')
                ?.textContent,
        ).toBe('2');
    });

    it('sends the filter to the server rather than sieving the loaded page', () => {
        withInbox([threadItem()]);

        const host = mountPanel();

        host.querySelector<HTMLElement>(
            '[data-test="threads-filter-all"]',
        )!.click();

        expect(get).toHaveBeenCalledWith(
            '/t/acme/c/general?nav=threads&filter=all',
            {},
            expect.objectContaining({ reset: ['threads'] }),
        );
    });

    it('ignores a click on the pill that is already active', () => {
        withInbox([threadItem()]);

        const host = mountPanel();

        host.querySelector<HTMLElement>(
            '[data-test="threads-filter-unread"]',
        )!.click();

        expect(get).not.toHaveBeenCalled();
    });

    it('reads the active pill off the URL, so a shared link opens on it', () => {
        page.url = '/t/acme/c/general?nav=threads&filter=all';
        withInbox([threadItem()]);

        const host = mountPanel();

        expect(
            host
                .querySelector('[data-test="threads-filter-all"]')
                ?.getAttribute('aria-pressed'),
        ).toBe('true');
    });
});

describe('mark all read', () => {
    it('posts the bulk clear', () => {
        withInbox([threadItem()], 1);

        const host = mountPanel();

        host.querySelector<HTMLElement>(
            '[data-test="threads-mark-all-read"]',
        )!.click();

        expect(post).toHaveBeenCalledWith(
            '/t/acme/threads/read-all',
            {},
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('is not offered at all with nothing to clear', () => {
        withInbox([], 0);

        const host = mountPanel();

        expect(
            host.querySelector('[data-test="threads-mark-all-read"]'),
        ).toBeNull();
    });
});

describe('the empty state', () => {
    it('says the viewer is caught up under the unread pill', () => {
        withInbox([], 0);

        const host = mountPanel();

        expect(
            host.querySelector('[data-test="threads-empty"]')?.textContent,
        ).toContain("You're all caught up");
    });

    it('explains how threads get here under the all pill', () => {
        page.url = '/t/acme/c/general?nav=threads&filter=all';
        withInbox([], 0);

        const host = mountPanel();

        expect(
            host.querySelector('[data-test="threads-empty"]')?.textContent,
        ).toContain("You're not following any threads yet");
    });
});
