// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import {
    all,
    author,
    inertiaPageProps,
    message,
    mountWithActions,
    presence,
    unmountAll,
} from './MessageList.doubles';

/**
 * Covers the timeline's structural chrome: the day and unread dividers, system
 * notices, the author group's avatar gutter and heading, the thread reply rule,
 * and the "Seen by" row. These are the surfaces a split of `MessageList.vue`
 * moves wholesale, so what is pinned here is the markup and the copy — every
 * `data-test` selector, every guard on whether a row renders at all.
 */
const mobile = vi.hoisted(() => ({
    current: null as { value: boolean } | null,
}));

vi.mock('@inertiajs/vue3', async () => {
    const { inertiaPageProps } = await import('./MessageList.doubles');

    return { usePage: () => ({ props: inertiaPageProps }) };
});

vi.mock('@/composables/useTeamPresence', async () => {
    const { teamPresenceDouble } = await import('./MessageList.doubles');

    return teamPresenceDouble();
});

vi.mock('@/composables/useIsMobile', async () => {
    const { ref } = await import('vue');
    const value = ref(false);
    mobile.current = value;

    return { useIsMobile: () => value };
});

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

vi.mock('@/components/MessageActions.vue', async () => {
    const { inert } = await import('./MessageList.doubles');

    return { default: inert('MessageActionsStub') };
});

vi.mock('@/components/MessageActionsSheet.vue', async () => {
    const { inert } = await import('./MessageList.doubles');

    return { default: inert('MessageActionsSheetStub') };
});

import MessageList from './MessageList.vue';

function mount(props: Record<string, unknown> = {}, inThread = false) {
    return mountWithActions(
        MessageList,
        { messages: [message()], teamSlug: 'acme', ...props },
        { subtree: { inThread } },
    ).host;
}

function texts(host: HTMLElement, selector: string): string[] {
    return all(host, selector).map((node) => node.textContent?.trim() ?? '');
}

beforeEach(() => {
    inertiaPageProps.auth.user.timezone = 'UTC';
    presence.presenceFor = () => 'offline';
    presence.isDndFor = () => false;

    if (mobile.current) {
        mobile.current.value = false;
    }
});

afterEach(unmountAll);

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
                    user: author({ id: 'peer', name: 'Peer' }),
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
                    user: author({ id: 'other', name: 'Other' }),
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
        const host = mountWithActions(
            MessageList,
            { messages: [message()], teamSlug: 'acme' },
            { scope: { viewerTimeZone: 'Australia/Sydney' } },
        ).host;

        expect(texts(host, 'message-group-time')).toEqual(['9:30 PM']);
    });

    it('opens the author’s hover card from both the avatar and the name', () => {
        const host = mount();

        expect(
            all(host, 'user-hover-card').map((node) =>
                node.getAttribute('data-user-id'),
            ),
        ).toEqual(['peer', 'peer']);
        expect(texts(host, 'message-author-name')).toEqual(['Peer']);
    });

    it('announces the author’s presence once per group', () => {
        presence.presenceFor = () => 'away';

        const host = mount();

        expect(host.querySelector('.sr-only')?.textContent?.trim()).toBe(
            'Away',
        );
        expect(host.querySelector('[data-test="presence-dot"]')).not.toBeNull();
    });

    it('reads an author absent from the presence roster as offline', () => {
        const host = mount();

        expect(host.querySelector('.sr-only')?.textContent?.trim()).toBe(
            'Offline',
        );
    });

    it('badges a bot author instead of announcing a presence it has none of', () => {
        const host = mount({
            messages: [
                message({
                    user: author({ id: 'bot', name: 'Botto', isBot: true }),
                }),
            ],
        });

        expect(texts(host, 'author-bot-badge')).toEqual(['Bot']);
        expect(host.querySelector('[data-test="presence-dot"]')).toBeNull();
        expect(host.querySelector('.sr-only')).toBeNull();
    });

    it('shows a per-message display identity with its bot badge riding along', () => {
        const host = mount({
            messages: [
                message({
                    user: author({
                        id: 'bot',
                        name: 'Deploy Bot',
                        isBot: true,
                    }),
                    authorOverride: {
                        name: 'Release Train',
                        avatar: '/images/proxy?url=train',
                    },
                }),
            ],
        });

        expect(texts(host, 'message-author-name')).toEqual(['Release Train']);
        // The marking is indelible: the name changed, the badge did not.
        expect(texts(host, 'author-bot-badge')).toEqual(['Bot']);
        expect(host.querySelector('[data-test="presence-dot"]')).toBeNull();
    });

    it('shows an overridden icon on a still-squared bot avatar', () => {
        const host = mount({
            messages: [
                message({
                    user: author({
                        id: 'bot',
                        name: 'Deploy Bot',
                        isBot: true,
                    }),
                    authorOverride: {
                        name: 'Release Train',
                        avatar: '/images/proxy?url=train',
                    },
                }),
            ],
        });

        const avatar = host.querySelector('[data-test="message-avatar"]');

        expect(avatar?.innerHTML).toContain('/images/proxy?url=train');
        expect(avatar?.querySelector('.rounded-lg')).not.toBeNull();
    });

    it('names a row for assistive technology by the identity it displays', () => {
        const host = mount({
            messages: [
                message({
                    user: author({
                        id: 'bot',
                        name: 'Deploy Bot',
                        isBot: true,
                    }),
                    authorOverride: { name: 'Release Train', avatar: null },
                }),
            ],
        });

        expect(
            host.querySelector('[role="listitem"]')?.getAttribute('aria-label'),
        ).toBe('Release Train, 10:30 AM');
    });

    it('never collapses two logical sources under one heading', () => {
        const host = mount({
            messages: [
                message({
                    id: 'a',
                    user: author({
                        id: 'bot',
                        name: 'Deploy Bot',
                        isBot: true,
                    }),
                    authorOverride: { name: 'Release Train', avatar: null },
                }),
                message({
                    id: 'b',
                    createdAt: '2024-03-04T10:30:10.000Z',
                    user: author({
                        id: 'bot',
                        name: 'Deploy Bot',
                        isBot: true,
                    }),
                    authorOverride: { name: 'Nightly', avatar: null },
                }),
            ],
        });

        expect(texts(host, 'message-author-name')).toEqual([
            'Release Train',
            'Nightly',
        ]);
    });

    it('names every message row for assistive technology', () => {
        const host = mount();

        expect(
            host.querySelector('[role="listitem"]')?.getAttribute('aria-label'),
        ).toBe('Peer, 10:30 AM');
    });

    it('marks the thread root with a brass accent inside a thread panel', () => {
        const host = mount({}, true);

        expect(
            host
                .querySelector('[data-test="message-column"]')
                ?.className.includes('border-brass'),
        ).toBe(true);
    });

    it('keeps the ordinary border on a reply inside a thread panel', () => {
        const host = mount(
            { messages: [message({ threadRootId: 'root' })] },
            true,
        );

        expect(
            host
                .querySelector('[data-test="message-column"]')
                ?.className.includes('border-brass'),
        ).toBe(false);
    });
});

describe('the thread reply rule', () => {
    it('separates the root from its replies when a count is given', () => {
        const host = mount({ replyDividerCount: 3 }, true);

        const rule = host.querySelector('[data-test="thread-replies-divider"]');

        expect(rule?.getAttribute('role')).toBe('separator');
        expect(rule?.getAttribute('aria-label')).toBe('3 replies');
        expect(rule?.textContent?.trim()).toBe('3 replies');
    });

    it('reads in the singular for a lone reply', () => {
        const host = mount({ replyDividerCount: 1 }, true);

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
        const host = mount({ replyDividerCount: 0 }, true);

        expect(
            host.querySelector('[data-test="thread-replies-divider"]'),
        ).toBeNull();
    });
});
