// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, defineComponent, h, ref } from 'vue';
import { translate } from '@/lib/i18n';
import type { ChannelReader, Message } from '@/types';

/**
 * Covers the affordances hanging off the timeline rather than sitting in a row:
 * a root's thread summary, the "Seen by" roster under the newest message, and
 * the flat (unwindowed) render the thread panel takes. Each moves as a unit when
 * `MessageList.vue` is split, so what is pinned here is the rendered markup and
 * the guards deciding whether it renders at all.
 */
const pageProps = vi.hoisted(() => ({
    auth: { user: { timezone: 'UTC' } },
    customEmojis: {} as Record<string, string>,
    userGroups: [] as Array<{ id: string }>,
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: pageProps }),
}));

const mobile = vi.hoisted(() => ({
    current: null as { value: boolean } | null,
}));

vi.mock('@/composables/useIsMobile', async () => {
    const { ref } = await import('vue');
    const value = ref(false);
    mobile.current = value;

    return { useIsMobile: () => value };
});

/** Renders an empty marker element, so a stubbed leaf is still findable. */
function marker(name: string) {
    return defineComponent({
        name,
        setup: () => () => h('div', { 'data-stub': name }),
    });
}

vi.mock('@/components/UserHoverCard.vue', () => ({
    default: defineComponent({
        name: 'UserHoverCardStub',
        setup:
            (_props, { slots }) =>
            () =>
                h('div', slots.default?.()),
    }),
}));

vi.mock('@/components/MessageActions.vue', () => ({
    default: marker('MessageActions'),
}));

vi.mock('@/components/MessageActionsSheet.vue', () => ({
    default: marker('MessageActionsSheet'),
}));

vi.mock('@/components/MessageAttachments.vue', () => ({
    default: marker('MessageAttachments'),
}));

vi.mock('@/components/MessagePoll.vue', () => ({
    default: marker('MessagePoll'),
}));

vi.mock('@/components/MessageReactions.vue', () => ({
    default: marker('MessageReactions'),
}));

vi.mock('@/components/MessageForward.vue', () => ({
    default: marker('MessageForward'),
}));

import MessageList from './MessageList.vue';

function message(overrides: Partial<Message> = {}): Message {
    return {
        id: 'm1',
        clientUuid: 'uuid-1',
        body: 'hello',
        type: 'standard',
        user: { id: 'peer', name: 'Peer' },
        createdAt: '2024-03-04T10:30:00.000Z',
        editedAt: null,
        isDeleted: false,
        mentions: [],
        linkPreviews: [],
        attachments: [],
        reactions: [],
        pin: null,
        poll: null,
        replyTo: null,
        forwardedFrom: null,
        threadRootId: null,
        sentToChannel: false,
        threadReplyCount: 0,
        threadLastReplyAt: null,
        threadParticipants: [],
        threadFollowed: false,
        threadUnread: false,
        threadUnreadReplyCount: 0,
        ...overrides,
    } as Message;
}

let active: Array<{ app: App; host: HTMLElement }> = [];

type Mounted = {
    host: HTMLElement;
    events: Array<[string, unknown]>;
};

function mount(props: Record<string, unknown> = {}): Mounted {
    const events: Array<[string, unknown]> = [];
    const host = document.createElement('div');
    document.body.appendChild(host);

    const app = createApp({
        render: () =>
            h(MessageList, {
                messages: [message()],
                teamSlug: 'acme',
                currentUserId: 'me',
                onJump: (id: string) => events.push(['jump', id]),
                onOpenThread: (id: string) => events.push(['openThread', id]),
                ...props,
            }),
    });
    app.config.globalProperties.$t = translate;
    app.mount(host);
    active.push({ app, host });

    return { host, events };
}

function find(host: HTMLElement, selector: string): HTMLElement | null {
    return host.querySelector<HTMLElement>(`[data-test="${selector}"]`);
}

beforeEach(() => {
    pageProps.customEmojis = {};
    pageProps.userGroups = [];
});

afterEach(() => {
    for (const { app, host } of active) {
        app.unmount();
        host.remove();
    }

    active = [];
});

describe('the thread summary', () => {
    const root = (overrides: Partial<Message> = {}) =>
        message({
            threadReplyCount: 2,
            threadParticipants: [
                { id: 'u1', name: 'Ada' },
                { id: 'u2', name: 'Bo' },
            ],
            ...overrides,
        });

    it('opens the thread when pressed, naming the reply count', () => {
        const { host, events } = mount({ messages: [root()] });

        const summary = find(host, 'thread-summary');

        expect(summary?.getAttribute('aria-label')).toBe(
            'View thread, 2 replies',
        );
        expect(summary?.textContent).toContain('2 replies');
        summary?.click();

        expect(events).toEqual([['openThread', 'm1']]);
    });

    it('reads in the singular for a lone reply', () => {
        const { host } = mount({
            messages: [root({ threadReplyCount: 1 })],
        });

        expect(find(host, 'thread-summary')?.getAttribute('aria-label')).toBe(
            'View thread, 1 reply',
        );
    });

    it('collapses the participant facepile past three avatars', () => {
        const { host } = mount({
            messages: [
                root({
                    threadParticipants: [
                        { id: 'u1', name: 'Ada' },
                        { id: 'u2', name: 'Bo' },
                        { id: 'u3', name: 'Cy' },
                        { id: 'u4', name: 'Di' },
                        { id: 'u5', name: 'Ed' },
                    ],
                }),
            ],
        });

        expect(find(host, 'thread-summary')?.textContent).toContain('+2');
    });

    it('raises a dot while the thread holds unread replies', () => {
        const { host } = mount({ messages: [root({ threadUnread: true })] });

        expect(
            find(host, 'thread-unread-dot')?.getAttribute('aria-label'),
        ).toBe('Unread replies');
    });

    it('names when the thread was last replied to', () => {
        const { host } = mount({
            messages: [root({ threadLastReplyAt: '2024-03-04T12:45:00.000Z' })],
        });

        expect(
            find(host, 'thread-summary')?.parentElement?.textContent,
        ).toContain('12:45 PM');
    });

    it('survives its root being deleted, since the thread outlives it', () => {
        const { host } = mount({
            messages: [root({ isDeleted: true })],
        });

        expect(find(host, 'thread-summary')).not.toBeNull();
    });

    it('stays out of a thread panel, where the reader is already in the thread', () => {
        const { host } = mount({ messages: [root()], inThread: true });

        expect(find(host, 'thread-summary')).toBeNull();
    });

    it('stays off a message with no replies', () => {
        const { host } = mount();

        expect(find(host, 'thread-summary')).toBeNull();
    });
});

describe('the "Seen by" row', () => {
    const readers = (...names: string[]): ChannelReader[] =>
        names.map((name) => ({
            user: { id: name, name },
            lastReadMessageId: 'm1',
        }));

    it('names a single reader', () => {
        const { host } = mount({ readers: readers('Alice') });

        expect(
            host.querySelector('[data-test="seen-by"]')?.getAttribute('title'),
        ).toBe('Seen by Alice');
    });

    it('joins two readers with "and"', () => {
        const { host } = mount({ readers: readers('Alice', 'Bob') });

        expect(
            host.querySelector('[data-test="seen-by"]')?.getAttribute('title'),
        ).toBe('Seen by Alice and Bob');
    });

    it('collapses the tail into a "+N" chip past three avatars', () => {
        const { host } = mount({
            readers: readers('Alice', 'Bob', 'Cara', 'Dan', 'Eve'),
        });

        const row = host.querySelector('[data-test="seen-by"]');

        expect(row?.getAttribute('title')).toBe(
            'Seen by Alice, Bob, Cara and 2 others',
        );
        expect(row?.querySelectorAll('img, .bg-primary\\/10')).toHaveLength(3);
        expect(row?.textContent).toContain('+2');
    });

    it('reads in the singular for a single overflow reader', () => {
        const { host } = mount({
            readers: readers('Alice', 'Bob', 'Cara', 'Dan'),
        });

        expect(
            host.querySelector('[data-test="seen-by"]')?.getAttribute('title'),
        ).toBe('Seen by Alice, Bob, Cara and 1 other');
    });

    it('drops the viewer’s own read position from the roster', () => {
        const { host } = mount({
            readers: [
                { user: { id: 'me', name: 'Me' }, lastReadMessageId: 'm1' },
            ],
        });

        expect(host.querySelector('[data-test="seen-by"]')).toBeNull();
    });

    it('stays out of a thread panel', () => {
        const { host } = mount({ inThread: true, readers: readers('Alice') });

        expect(host.querySelector('[data-test="seen-by"]')).toBeNull();
    });

    it('stays out of an empty timeline', () => {
        const { host } = mount({ messages: [], readers: readers('Alice') });

        expect(host.querySelector('[data-test="seen-by"]')).toBeNull();
    });
});

describe('the windowed timeline', () => {
    it('renders every row flat when the consumer does not opt into windowing', () => {
        const { host } = mount({
            messages: [
                message({ id: 'a' }),
                message({ id: 'b', createdAt: '2024-03-04T10:30:10.000Z' }),
            ],
        });

        expect(host.querySelectorAll('[role="listitem"]')).toHaveLength(2);
        expect(host.querySelector('[data-test="message-skeleton"]')).toBeNull();
    });

    it('exposes the jump helpers the parent drives the list with', () => {
        const exposed = ref<Record<string, unknown> | null>(null);
        const host = document.createElement('div');
        document.body.appendChild(host);

        const app = createApp({
            render: () =>
                h(MessageList, {
                    messages: [message()],
                    teamSlug: 'acme',
                    currentUserId: 'me',
                    ref: (instance: unknown) => {
                        exposed.value = instance as Record<string, unknown>;
                    },
                }),
        });
        app.config.globalProperties.$t = translate;
        app.mount(host);
        active.push({ app, host });

        expect(typeof exposed.value?.scrollToIndex).toBe('function');
        expect(typeof exposed.value?.scrollToLatest).toBe('function');
    });
});
