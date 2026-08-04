import { describe, expect, it } from 'vitest';
import { EMPTY_DIGEST } from '@/lib/unreadDigest';
import {
    formatUnreadCount,
    summarizeUnreadElsewhere,
} from '@/lib/unreadElsewhere';
import type { UnreadElsewhereSummary } from '@/lib/unreadElsewhere';
import type { Channel } from '@/types/channels';
import type { UnreadCounts } from '@/types/unread';

/** A conversation as the sidebar lists it, carrying no badge of its own. */
function channel(overrides: Partial<Channel> = {}): Channel {
    return {
        id: 'ch-1',
        name: 'design',
        slug: 'design',
        visibility: 'public',
        topic: null,
        description: null,
        isGeneral: false,
        isArchived: false,
        muted: false,
        notificationLevel: 'all',
        hasDraft: false,
        draft: null,
        starred: false,
        sectionId: null,
        position: 0,
        isDirect: false,
        isGroupDirect: false,
        dmUserId: null,
        dmParticipants: null,
        lastActivityAt: null,
        ...overrides,
    };
}

/**
 * Roll up the given conversations, each paired with what the digest says is
 * waiting in it. A conversation given no counts is absent from the digest, which
 * is how a read — or a suppressed — one arrives.
 */
function summarize(
    rows: Array<[Channel, UnreadCounts?]>,
    activeChannelId: string | null = null,
): UnreadElsewhereSummary {
    return summarizeUnreadElsewhere(
        rows.map(([conversation]) => conversation),
        {
            ...EMPTY_DIGEST,
            channels: Object.fromEntries(
                rows
                    .filter(([, counts]) => counts !== undefined)
                    .map(([conversation, counts]) => [
                        conversation.id,
                        counts as UnreadCounts,
                    ]),
            ),
        },
        activeChannelId,
    );
}

describe('summarizeUnreadElsewhere', () => {
    it('reports nothing for an empty conversation list', () => {
        expect(summarize([])).toEqual({ count: 0, hasUnread: false });
    });

    it('leaves plain channel unread out of the numeral but still inks the rail', () => {
        expect(summarize([[channel(), { unread: 3, mention: 0 }]])).toEqual({
            count: 0,
            hasUnread: true,
        });
    });

    it('counts channel @mentions', () => {
        expect(summarize([[channel(), { unread: 5, mention: 2 }]])).toEqual({
            count: 2,
            hasUnread: true,
        });
    });

    it('counts every unread message in a DM, mention or not', () => {
        const summary = summarize([
            [
                channel({ id: 'dm-1', isDirect: true }),
                { unread: 1, mention: 0 },
            ],
        ]);

        expect(summary).toEqual({ count: 1, hasUnread: true });
    });

    it('does not count an @mention inside a DM twice', () => {
        const summary = summarize([
            [
                channel({ id: 'dm-1', isDirect: true }),
                { unread: 2, mention: 1 },
            ],
        ]);

        expect(summary.count).toBe(2);
    });

    it('adds the design brief up to one DM, with the three plain unread only inking the rail', () => {
        const summary = summarize([
            [channel({ id: 'ch-design' }), { unread: 3, mention: 0 }],
            [
                channel({ id: 'dm-ep', isDirect: true }),
                { unread: 1, mention: 0 },
            ],
        ]);

        expect(summary).toEqual({ count: 1, hasUnread: true });
    });

    it('excludes the conversation the viewer already has open', () => {
        const summary = summarize(
            [
                [
                    channel({ id: 'dm-open', isDirect: true }),
                    { unread: 4, mention: 0 },
                ],
                [channel({ id: 'ch-other' }), { unread: 2, mention: 0 }],
            ],
            'dm-open',
        );

        expect(summary).toEqual({ count: 0, hasUnread: true });
    });

    it('reports nothing when the only unread sits in the open conversation', () => {
        const summary = summarize(
            [[channel({ id: 'ch-open' }), { unread: 9, mention: 2 }]],
            'ch-open',
        );

        expect(summary).toEqual({ count: 0, hasUnread: false });
    });

    /**
     * Muting and the "nothing" level are applied server-side, so a silenced
     * conversation is simply absent from the digest — the rollup never has to
     * re-decide whether it is allowed to shout. The row is still listed, and
     * still dimmed, which is the distinction a single aggregate cannot draw.
     */
    it('reports nothing for a conversation the digest does not name', () => {
        const summary = summarize([
            [channel({ id: 'dm-muted', isDirect: true, muted: true })],
            [channel({ id: 'ch-silent', notificationLevel: 'nothing' })],
        ]);

        expect(summary).toEqual({ count: 0, hasUnread: false });
    });

    it('keeps a "mentions only" conversation, whose ordinary unread the server has already zeroed', () => {
        const summary = summarize([
            [
                channel({ notificationLevel: 'mentions' }),
                { unread: 0, mention: 2 },
            ],
        ]);

        expect(summary).toEqual({ count: 2, hasUnread: true });
    });

    it('sums the numeral across every remaining conversation', () => {
        const summary = summarize([
            [channel({ id: 'ch-a' }), { unread: 4, mention: 2 }],
            [
                channel({ id: 'dm-b', isDirect: true }),
                { unread: 3, mention: 0 },
            ],
            [channel({ id: 'ch-c' }), { unread: 1, mention: 0 }],
        ]);

        expect(summary).toEqual({ count: 5, hasUnread: true });
    });
});

describe('formatUnreadCount', () => {
    it('spells out a count within the cap', () => {
        expect(formatUnreadCount(4)).toBe('4');
        expect(formatUnreadCount(99)).toBe('99');
    });

    it('caps anything past 99', () => {
        expect(formatUnreadCount(100)).toBe('99+');
        expect(formatUnreadCount(1240)).toBe('99+');
    });
});
