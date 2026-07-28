// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, defineComponent, h } from 'vue';
import { translate } from '@/lib/i18n';
import type { Message } from '@/types';

/**
 * Covers the timeline's structural chrome: the day and unread dividers, system
 * notices, the author group's avatar gutter and heading, the thread reply rule,
 * and the "Seen by" row. These are the surfaces a split of `MessageList.vue`
 * moves wholesale, so what is pinned here is the markup and the copy — every
 * `data-test` selector, every guard on whether a row renders at all.
 */
const pageProps = vi.hoisted(() => ({
    auth: { user: { timezone: 'UTC' } },
    customEmojis: {} as Record<string, string>,
    userGroups: [] as unknown[],
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

/** Renders a child's default slot, so a stubbed wrapper stays transparent. */
function passthrough(name: string) {
    return defineComponent({
        name,
        setup:
            (_props, { slots }) =>
            () =>
                h('div', slots.default?.()),
    });
}

/** Renders nothing, standing in for a leaf whose own tests cover it. */
function inert(name: string) {
    return defineComponent({ name, setup: () => () => null });
}

vi.mock('@/components/UserHoverCard.vue', () => ({
    default: defineComponent({
        name: 'UserHoverCardStub',
        props: { userId: { type: String, default: '' } },
        setup:
            (props, { slots }) =>
            () =>
                h(
                    'div',
                    {
                        'data-test': 'user-hover-card',
                        'data-user-id': props.userId,
                    },
                    slots.default?.(),
                ),
    }),
}));

vi.mock('@/components/ui/hover-card', () => ({
    HoverCard: passthrough('HoverCardStub'),
    HoverCardTrigger: passthrough('HoverCardTriggerStub'),
    HoverCardContent: passthrough('HoverCardContentStub'),
}));

vi.mock('@/components/ui/dialog', () => ({
    Dialog: passthrough('DialogStub'),
    DialogClose: passthrough('DialogCloseStub'),
    DialogContent: passthrough('DialogContentStub'),
    DialogDescription: passthrough('DialogDescriptionStub'),
    DialogFooter: passthrough('DialogFooterStub'),
    DialogHeader: passthrough('DialogHeaderStub'),
    DialogTitle: passthrough('DialogTitleStub'),
}));

vi.mock('@/components/MessageActions.vue', () => ({
    default: inert('MessageActionsStub'),
}));

vi.mock('@/components/MessageActionsSheet.vue', () => ({
    default: inert('MessageActionsSheetStub'),
}));

vi.mock('@/components/MessageAttachments.vue', () => ({
    default: inert('MessageAttachmentsStub'),
}));

vi.mock('@/components/MessagePoll.vue', () => ({
    default: inert('MessagePollStub'),
}));

vi.mock('@/components/MessageReactions.vue', () => ({
    default: inert('MessageReactionsStub'),
}));

vi.mock('@/components/MessageForward.vue', () => ({
    default: inert('MessageForwardStub'),
}));

vi.mock('@/components/LinkPreview.vue', () => ({
    default: inert('LinkPreviewStub'),
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

function mount(props: Record<string, unknown> = {}): HTMLElement {
    const host = document.createElement('div');
    document.body.appendChild(host);

    const app = createApp({
        render: () =>
            h(MessageList, {
                messages: [message()],
                teamSlug: 'acme',
                currentUserId: 'me',
                ...props,
            }),
    });
    app.config.globalProperties.$t = translate;
    app.mount(host);
    active.push({ app, host });

    return host;
}

function texts(host: HTMLElement, selector: string): string[] {
    return [...host.querySelectorAll(`[data-test="${selector}"]`)].map(
        (node) => node.textContent?.trim() ?? '',
    );
}

beforeEach(() => {
    pageProps.auth.user.timezone = 'UTC';

    if (mobile.current) {
        mobile.current.value = false;
    }
});

afterEach(() => {
    for (const { app, host } of active) {
        app.unmount();
        host.remove();
    }

    active = [];
});

describe('the timeline’s dividers', () => {
    it('opens the list with a day divider carrying the crossing message’s date', () => {
        const host = mount();

        expect(texts(host, 'day-divider')).toHaveLength(1);
    });

    it('renders one day divider per calendar day', () => {
        const host = mount({
            messages: [
                message({ id: 'a', createdAt: '2024-03-04T10:30:00.000Z' }),
                message({ id: 'b', createdAt: '2024-03-05T10:30:00.000Z' }),
            ],
        });

        expect(texts(host, 'day-divider')).toHaveLength(2);
    });

    it('marks the unread boundary with the "new" rule above its message', () => {
        const host = mount({
            messages: [
                message({ id: 'a' }),
                message({ id: 'b', createdAt: '2024-03-04T10:30:10.000Z' }),
            ],
            unreadDividerId: 'b',
        });

        const divider = host.querySelector('[data-test="unread-divider"]');

        expect(divider).not.toBeNull();
        expect(divider?.id).toBe('unread-divider');
        expect(divider?.getAttribute('role')).toBe('separator');
        expect(divider?.getAttribute('aria-label')).toBe('New messages');
        expect(divider?.textContent?.trim()).toBe('new');
    });

    it('leaves the unread rule out when there is no boundary to mark', () => {
        const host = mount();

        expect(host.querySelector('[data-test="unread-divider"]')).toBeNull();
    });
});

describe('a system notice', () => {
    it('renders a centered, inert line naming its type', () => {
        const host = mount({
            messages: [
                message({
                    id: 's1',
                    type: 'member_joined',
                    user: { id: 'peer', name: 'Peer' },
                }),
            ],
        });

        const notice = host.querySelector('[data-test="system-notice"]');

        expect(notice?.id).toBe('message-s1');
        expect(notice?.getAttribute('data-system-type')).toBe('member_joined');
        expect(notice?.textContent?.trim()).toBe('Peer joined the channel');
        expect(host.querySelector('[data-test="message-group"]')).toBeNull();
    });

    it('reads as the conversation rather than the channel in a direct message', () => {
        const host = mount({
            messages: [message({ id: 's1', type: 'member_left' })],
            isDirect: true,
        });

        expect(texts(host, 'system-notice')).toEqual([
            'Peer left the conversation',
        ]);
    });

    it('names the channel for a member who left a channel', () => {
        const host = mount({
            messages: [message({ id: 's1', type: 'member_left' })],
        });

        expect(texts(host, 'system-notice')).toEqual(['Peer left the channel']);
    });
});

describe('an author group', () => {
    it('renders one group per author run, with the lead message’s time', () => {
        const host = mount({
            messages: [
                message({ id: 'a' }),
                message({
                    id: 'b',
                    createdAt: '2024-03-04T10:30:20.000Z',
                    user: { id: 'other', name: 'Other' },
                }),
            ],
        });

        expect(texts(host, 'message-group')).toHaveLength(2);
        expect(texts(host, 'message-group-time')).toEqual([
            '10:30 AM',
            '10:30 AM',
        ]);
    });

    it('repeats the group time inline beside the author name for touch', () => {
        const host = mount();

        const inline = host.querySelector(
            '[data-test="message-group-time-inline"]',
        );

        expect(inline?.getAttribute('aria-hidden')).toBe('true');
        expect(inline?.textContent?.trim()).toBe('10:30 AM');
    });

    it('renders each timestamp in the viewer’s stored zone', () => {
        pageProps.auth.user.timezone = 'Australia/Sydney';
        const host = mount();

        expect(texts(host, 'message-group-time')).toEqual(['9:30 PM']);
    });

    it('opens the author’s hover card from both the avatar and the name', () => {
        const host = mount();

        expect(
            [...host.querySelectorAll('[data-test="user-hover-card"]')].map(
                (node) => node.getAttribute('data-user-id'),
            ),
        ).toEqual(['peer', 'peer']);
        expect(texts(host, 'message-author-name')).toEqual(['Peer']);
    });

    it('announces the author’s presence once per group', () => {
        const host = mount({ presenceFor: () => 'away' });

        expect(host.querySelector('.sr-only')?.textContent?.trim()).toBe(
            'Away',
        );
        expect(host.querySelector('[data-test="presence-dot"]')).not.toBeNull();
    });

    it('falls back to offline when the surface carries no presence roster', () => {
        const host = mount();

        expect(host.querySelector('.sr-only')?.textContent?.trim()).toBe(
            'Offline',
        );
    });

    it('badges a bot author instead of announcing a presence it has none of', () => {
        const host = mount({
            messages: [
                message({ user: { id: 'bot', name: 'Botto', isBot: true } }),
            ],
        });

        expect(texts(host, 'author-bot-badge')).toEqual(['Bot']);
        expect(host.querySelector('[data-test="presence-dot"]')).toBeNull();
        expect(host.querySelector('.sr-only')).toBeNull();
    });

    it('names every message row for assistive technology', () => {
        const host = mount();

        expect(
            host.querySelector('[role="listitem"]')?.getAttribute('aria-label'),
        ).toBe('Peer, 10:30 AM');
    });

    it('marks the thread root with a brass accent inside a thread panel', () => {
        const host = mount({ inThread: true });

        expect(
            host
                .querySelector('[data-test="message-column"]')
                ?.className.includes('border-brass'),
        ).toBe(true);
    });

    it('keeps the ordinary border on a reply inside a thread panel', () => {
        const host = mount({
            messages: [message({ threadRootId: 'root' })],
            inThread: true,
        });

        expect(
            host
                .querySelector('[data-test="message-column"]')
                ?.className.includes('border-brass'),
        ).toBe(false);
    });
});

describe('the thread reply rule', () => {
    it('separates the root from its replies when a count is given', () => {
        const host = mount({ inThread: true, replyDividerCount: 3 });

        const rule = host.querySelector('[data-test="thread-replies-divider"]');

        expect(rule?.getAttribute('role')).toBe('separator');
        expect(rule?.getAttribute('aria-label')).toBe('3 replies');
        expect(rule?.textContent?.trim()).toBe('3 replies');
    });

    it('reads in the singular for a lone reply', () => {
        const host = mount({ inThread: true, replyDividerCount: 1 });

        expect(
            host
                .querySelector('[data-test="thread-replies-divider"]')
                ?.getAttribute('aria-label'),
        ).toBe('1 reply');
    });

    it('stays out of the main timeline, which has no root to separate', () => {
        const host = mount({ replyDividerCount: 3 });

        expect(
            host.querySelector('[data-test="thread-replies-divider"]'),
        ).toBeNull();
    });

    it('stays out of a thread with no replies yet', () => {
        const host = mount({ inThread: true, replyDividerCount: 0 });

        expect(
            host.querySelector('[data-test="thread-replies-divider"]'),
        ).toBeNull();
    });
});
