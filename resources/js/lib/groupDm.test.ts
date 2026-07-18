import { describe, expect, it } from 'vitest';
import {
    channelSignature,
    directMessageSignature,
    findMatchingDirectMessage,
    firstName,
    groupDmMastheadName,
    groupDmSidebarName,
} from '@/lib/groupDm';
import type { Channel, DmParticipant } from '@/types/channels';

function participant(id: string, name: string): DmParticipant {
    return { id, name, avatar: null, isBot: false };
}

function dmChannel(id: string, participants: DmParticipant[]): Channel {
    return {
        id,
        name: participants.map((p) => p.name).join(', '),
        slug: `dm-${id}`,
        visibility: 'private',
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
        isDirect: true,
        isGroupDirect: participants.length > 1,
        dmUserId: null,
        dmParticipants: participants,
        lastActivityAt: null,
    };
}

describe('firstName', () => {
    it('returns the leading token', () => {
        expect(firstName('Jonas Reed')).toBe('Jonas');
        expect(firstName('  Ana   Pires ')).toBe('Ana');
        expect(firstName('Tomas')).toBe('Tomas');
    });
});

describe('groupDmSidebarName', () => {
    it('joins every first name when the group fits', () => {
        expect(
            groupDmSidebarName([
                participant('1', 'Jonas Reed'),
                participant('2', 'Ana Pires'),
                participant('3', 'Tomas K'),
            ]),
        ).toBe('Jonas, Ana, Tomas');
    });

    it('collapses the overflow into a +N suffix', () => {
        expect(
            groupDmSidebarName([
                participant('1', 'Maya Chen'),
                participant('2', 'Jonas Reed'),
                participant('3', 'Ana Pires'),
                participant('4', 'Tomas K'),
            ]),
        ).toBe('Maya, Jonas, +2');
    });
});

describe('groupDmMastheadName', () => {
    it('joins the last name with an ampersand', () => {
        expect(
            groupDmMastheadName([
                participant('1', 'Jonas Reed'),
                participant('2', 'Ana Pires'),
                participant('3', 'Tomas K'),
            ]),
        ).toBe('Jonas, Ana & Tomas');
    });

    it('renders a single participant plainly and an empty set as an empty string', () => {
        expect(groupDmMastheadName([participant('1', 'Jonas Reed')])).toBe(
            'Jonas',
        );
        expect(groupDmMastheadName([])).toBe('');
    });
});

describe('directMessageSignature', () => {
    it('is order- and duplicate-insensitive', () => {
        expect(directMessageSignature(['b', 'a', 'c'])).toBe('a:b:c');
        expect(directMessageSignature(['a', 'a', 'b'])).toBe('a:b');
    });
});

describe('channelSignature', () => {
    it('folds the viewer into the participant set', () => {
        const channel = dmChannel('x', [
            participant('2', 'Ana'),
            participant('3', 'Tomas'),
        ]);

        expect(channelSignature(channel, '1')).toBe('1:2:3');
    });

    it('is null for a channel without participant data', () => {
        const channel = dmChannel('x', []);
        channel.dmParticipants = null;

        expect(channelSignature(channel, '1')).toBeNull();
    });
});

describe('findMatchingDirectMessage', () => {
    const channels = [
        dmChannel('a', [participant('2', 'Ana'), participant('3', 'Tomas')]),
        dmChannel('b', [participant('4', 'Maya')]),
    ];

    it('finds a conversation with the same member set', () => {
        const match = findMatchingDirectMessage(
            channels,
            ['1', '2', '3'],
            'current',
            '1',
        );

        expect(match?.id).toBe('a');
    });

    it('ignores the conversation the flow started from', () => {
        expect(
            findMatchingDirectMessage(channels, ['1', '2', '3'], 'a', '1'),
        ).toBeUndefined();
    });

    it('returns undefined when no set matches', () => {
        expect(
            findMatchingDirectMessage(channels, ['1', '9'], 'current', '1'),
        ).toBeUndefined();
    });
});
