// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, ref } from 'vue';
import type { ChannelReader } from '@/types';
import {
    find,
    inertiaPageProps,
    message,
    mountWithActions,
    unmountAll,
} from './MessageList.doubles';

/**
 * Covers the affordances hanging off the timeline rather than sitting in a row:
 * a root's thread summary, the "Seen by" roster under the newest message, and
 * the flat (unwindowed) render the thread panel takes. Each moves as a unit when
 * `MessageList.vue` is split, so what is pinned here is the rendered markup and
 * the guards deciding whether it renders at all.
 */
vi.mock('@inertiajs/vue3', async () => {
    const { inertiaPageProps } = await import('./MessageList.doubles');

    return { usePage: () => ({ props: inertiaPageProps }) };
});

vi.mock('@/composables/useIsMobile', async () => {
    const { ref } = await import('vue');
    const value = ref(false);

    return { useIsMobile: () => value };
});

vi.mock('@/components/UserHoverCard.vue', () => ({
    default: defineComponent({
        name: 'UserHoverCardStub',
        setup:
            (_props, { slots }) =>
            () =>
                h('div', slots.default?.()),
    }),
}));

vi.mock('@/components/MessageActions.vue', async () => {
    const { marker } = await import('./MessageList.doubles');

    return { default: marker('MessageActions') };
});

vi.mock('@/components/MessageActionsSheet.vue', async () => {
    const { marker } = await import('./MessageList.doubles');

    return { default: marker('MessageActionsSheet') };
});

vi.mock('@/components/MessageAttachments.vue', async () => {
    const { marker } = await import('./MessageList.doubles');

    return { default: marker('MessageAttachments') };
});

vi.mock('@/components/MessagePoll.vue', async () => {
    const { marker } = await import('./MessageList.doubles');

    return { default: marker('MessagePoll') };
});

vi.mock('@/components/MessageReactions.vue', async () => {
    const { marker } = await import('./MessageList.doubles');

    return { default: marker('MessageReactions') };
});

vi.mock('@/components/MessageForward.vue', async () => {
    const { marker } = await import('./MessageList.doubles');

    return { default: marker('MessageForward') };
});

import MessageList from './MessageList.vue';

function mount(props: Record<string, unknown> = {}, inThread = false) {
    return mountWithActions(
        MessageList,
        { messages: [message()], teamSlug: 'acme', ...props },
        { subtree: { inThread } },
    );
}

beforeEach(() => {
    inertiaPageProps.customEmojis = {};
    inertiaPageProps.userGroups = [];
});

afterEach(unmountAll);

describe('the thread summary', () => {
    const root = (overrides: Partial<ReturnType<typeof message>> = {}) =>
        message({
            threadReplyCount: 2,
            threadParticipants: [
                { id: 'u1', name: 'Ada', avatar: null },
                { id: 'u2', name: 'Bo', avatar: null },
            ],
            ...overrides,
        });

    it('opens the thread when pressed, naming the reply count', () => {
        const { host, actions } = mount({ messages: [root()] });

        const summary = find(host, 'thread-summary');

        expect(summary?.getAttribute('aria-label')).toBe(
            'View thread, 2 replies',
        );
        expect(summary?.textContent).toContain('2 replies');
        summary?.click();

        expect(actions.openThread).toHaveBeenCalledExactlyOnceWith('m1');
    });

    it('reads in the singular for a lone reply', () => {
        const { host } = mount({ messages: [root({ threadReplyCount: 1 })] });

        expect(find(host, 'thread-summary')?.getAttribute('aria-label')).toBe(
            'View thread, 1 reply',
        );
    });

    it('collapses the participant facepile past three avatars', () => {
        const { host } = mount({
            messages: [
                root({
                    threadParticipants: [
                        { id: 'u1', name: 'Ada', avatar: null },
                        { id: 'u2', name: 'Bo', avatar: null },
                        { id: 'u3', name: 'Cy', avatar: null },
                        { id: 'u4', name: 'Di', avatar: null },
                        { id: 'u5', name: 'Ed', avatar: null },
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
        const { host } = mount({ messages: [root({ isDeleted: true })] });

        expect(find(host, 'thread-summary')).not.toBeNull();
    });

    it('stays out of a thread panel, where the reader is already in the thread', () => {
        const { host } = mount({ messages: [root()] }, true);

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
            user: {
                id: name,
                name,
                avatar: null,
                isBot: false,
                status: null,
                presence: 'active',
                isDnd: false,
            },
            lastReadMessageId: 'm1',
        }));

    it('names a single reader', () => {
        const { host } = mount({ readers: readers('Alice') });

        expect(find(host, 'seen-by')?.getAttribute('title')).toBe(
            'Seen by Alice',
        );
    });

    it('joins two readers with "and"', () => {
        const { host } = mount({ readers: readers('Alice', 'Bob') });

        expect(find(host, 'seen-by')?.getAttribute('title')).toBe(
            'Seen by Alice and Bob',
        );
    });

    it('collapses the tail into a "+N" chip past three avatars', () => {
        const { host } = mount({
            readers: readers('Alice', 'Bob', 'Cara', 'Dan', 'Eve'),
        });

        const row = find(host, 'seen-by');

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

        expect(find(host, 'seen-by')?.getAttribute('title')).toBe(
            'Seen by Alice, Bob, Cara and 1 other',
        );
    });

    it('drops the viewer’s own read position from the roster', () => {
        const { host } = mount({
            readers: [
                { user: { id: 'me', name: 'Me' }, lastReadMessageId: 'm1' },
            ],
        });

        expect(find(host, 'seen-by')).toBeNull();
    });

    it('stays out of a thread panel', () => {
        const { host } = mount({ readers: readers('Alice') }, true);

        expect(find(host, 'seen-by')).toBeNull();
    });

    it('stays out of an empty timeline', () => {
        const { host } = mount({ messages: [], readers: readers('Alice') });

        expect(find(host, 'seen-by')).toBeNull();
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
        expect(find(host, 'message-skeleton')).toBeNull();
    });

    it('exposes the jump helpers the parent drives the list with', () => {
        const exposed = ref<Record<string, unknown> | null>(null);

        mount({
            ref: (instance: unknown) => {
                exposed.value = instance as Record<string, unknown>;
            },
        });

        expect(typeof exposed.value?.scrollToIndex).toBe('function');
        expect(typeof exposed.value?.scrollToLatest).toBe('function');
    });
});
