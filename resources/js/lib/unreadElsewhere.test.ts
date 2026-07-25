import { describe, expect, it } from 'vitest';
import {
    formatUnreadCount,
    summarizeUnreadElsewhere,
} from '@/lib/unreadElsewhere';
import type { Channel } from '@/types/channels';

function channel(overrides: Partial<Channel> = {}): Channel {
    return {
        id: 'ch-1',
        name: 'design',
        slug: 'design',
        visibility: 'public',
        topic: null,
        isGeneral: false,
        isArchived: false,
        muted: false,
        notificationLevel: 'all',
        unreadCount: 0,
        mentionCount: 0,
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

describe('summarizeUnreadElsewhere', () => {
    it('reports nothing for an empty conversation list', () => {
        expect(summarizeUnreadElsewhere([], null)).toEqual({
            count: 0,
            hasUnread: false,
        });
    });

    it('leaves plain channel unread out of the numeral but still inks the rail', () => {
        const summary = summarizeUnreadElsewhere(
            [channel({ unreadCount: 3 })],
            null,
        );

        expect(summary).toEqual({ count: 0, hasUnread: true });
    });

    it('counts channel @mentions', () => {
        const summary = summarizeUnreadElsewhere(
            [channel({ unreadCount: 5, mentionCount: 2 })],
            null,
        );

        expect(summary).toEqual({ count: 2, hasUnread: true });
    });

    it('counts every unread message in a DM, mention or not', () => {
        const summary = summarizeUnreadElsewhere(
            [channel({ id: 'dm-1', isDirect: true, unreadCount: 1 })],
            null,
        );

        expect(summary).toEqual({ count: 1, hasUnread: true });
    });

    it('does not count an @mention inside a DM twice', () => {
        const summary = summarizeUnreadElsewhere(
            [
                channel({
                    id: 'dm-1',
                    isDirect: true,
                    unreadCount: 2,
                    mentionCount: 1,
                }),
            ],
            null,
        );

        expect(summary.count).toBe(2);
    });

    it('adds the design brief up to one DM, with the three plain unread only inking the rail', () => {
        const summary = summarizeUnreadElsewhere(
            [
                channel({ id: 'ch-design', unreadCount: 3 }),
                channel({ id: 'dm-ep', isDirect: true, unreadCount: 1 }),
            ],
            null,
        );

        expect(summary).toEqual({ count: 1, hasUnread: true });
    });

    it('excludes the conversation the viewer already has open', () => {
        const summary = summarizeUnreadElsewhere(
            [
                channel({ id: 'dm-open', isDirect: true, unreadCount: 4 }),
                channel({ id: 'ch-other', unreadCount: 2 }),
            ],
            'dm-open',
        );

        expect(summary).toEqual({ count: 0, hasUnread: true });
    });

    it('reports nothing when the only unread sits in the open conversation', () => {
        const summary = summarizeUnreadElsewhere(
            [channel({ id: 'ch-open', unreadCount: 9, mentionCount: 2 })],
            'ch-open',
        );

        expect(summary).toEqual({ count: 0, hasUnread: false });
    });

    it('silences a muted conversation entirely', () => {
        const summary = summarizeUnreadElsewhere(
            [
                channel({
                    id: 'dm-muted',
                    isDirect: true,
                    muted: true,
                    unreadCount: 6,
                    mentionCount: 3,
                }),
            ],
            null,
        );

        expect(summary).toEqual({ count: 0, hasUnread: false });
    });

    it('silences a conversation set to the "nothing" notification level', () => {
        const summary = summarizeUnreadElsewhere(
            [
                channel({
                    notificationLevel: 'nothing',
                    unreadCount: 6,
                    mentionCount: 3,
                }),
            ],
            null,
        );

        expect(summary).toEqual({ count: 0, hasUnread: false });
    });

    it('keeps a "mentions only" conversation, whose ordinary unread the server has already zeroed', () => {
        const summary = summarizeUnreadElsewhere(
            [
                channel({
                    notificationLevel: 'mentions',
                    unreadCount: 0,
                    mentionCount: 2,
                }),
            ],
            null,
        );

        expect(summary).toEqual({ count: 2, hasUnread: true });
    });

    it('sums the numeral across every remaining conversation', () => {
        const summary = summarizeUnreadElsewhere(
            [
                channel({ id: 'ch-a', mentionCount: 2, unreadCount: 4 }),
                channel({ id: 'dm-b', isDirect: true, unreadCount: 3 }),
                channel({ id: 'ch-c', unreadCount: 1 }),
            ],
            null,
        );

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
