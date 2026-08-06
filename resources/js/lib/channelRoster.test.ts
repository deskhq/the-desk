import { describe, expect, it } from 'vitest';
import { channelRoster } from '@/lib/channelRoster';
import type { Channel, RosterMember } from '@/types';

function member(overrides: Partial<RosterMember> = {}): RosterMember {
    return {
        id: 'u-1',
        name: 'Amy Member',
        avatar: null,
        isBot: false,
        status: null,
        presence: 'active',
        isDnd: false,
        ...overrides,
    };
}

const viewer = member({ id: 'me', name: 'Zoe Owner' });

function channel(overrides: Partial<Channel> = {}): Channel {
    return {
        isDirect: false,
        dmParticipants: null,
        ...overrides,
    } as Channel;
}

describe('a standard channel', () => {
    it('resolves its roster from the shared team roster', () => {
        const amy = member({ id: 'u-1', name: 'Amy Member' });

        expect(
            channelRoster({
                channel: channel(),
                teamMembers: [amy, viewer],
                botMembers: [],
                viewerId: viewer.id,
            }),
        ).toEqual([amy, viewer]);
    });

    it('appends the channel bots the shared roster cannot hold', () => {
        const bot = member({ id: 'bot-1', name: 'Deploy Bot', isBot: true });

        const roster = channelRoster({
            channel: channel(),
            teamMembers: [viewer],
            botMembers: [bot],
            viewerId: viewer.id,
        });

        // Humans first, as the facepile draws them; the bot lands after.
        expect(roster.map((entry) => entry.id)).toEqual(['me', 'bot-1']);
        expect(roster[1]?.isBot).toBe(true);
    });
});

describe('a direct message', () => {
    it('resolves its roster from its own participants, not the team roster', () => {
        const counterpart = member({ id: 'u-1', name: 'Amy Member' });
        const bystander = member({ id: 'u-2', name: 'Bob Bystander' });

        const roster = channelRoster({
            channel: channel({
                isDirect: true,
                dmParticipants: [counterpart],
            }),
            teamMembers: [counterpart, bystander, viewer],
            botMembers: [],
            viewerId: viewer.id,
        });

        expect(roster.map((entry) => entry.name)).toEqual([
            'Amy Member',
            'Zoe Owner',
        ]);
    });

    it('still names a participant who has left the team', () => {
        const departed = member({ id: 'u-gone', name: 'Ada Departed' });

        const roster = channelRoster({
            channel: channel({ isDirect: true, dmParticipants: [departed] }),
            // The shared roster no longer holds them, which is exactly why the
            // conversation carries its own participants.
            teamMembers: [viewer],
            botMembers: [],
            viewerId: viewer.id,
        });

        expect(roster.map((entry) => entry.id)).toEqual(['u-gone', 'me']);
    });

    it('reads as the viewer alone in their own self-DM', () => {
        expect(
            channelRoster({
                channel: channel({ isDirect: true, dmParticipants: [] }),
                teamMembers: [viewer],
                botMembers: [],
                viewerId: viewer.id,
            }),
        ).toEqual([viewer]);
    });

    it('holds only its participants when the viewer is not on the roster yet', () => {
        const counterpart = member({ id: 'u-1', name: 'Amy Member' });

        expect(
            channelRoster({
                channel: channel({
                    isDirect: true,
                    dmParticipants: [counterpart],
                }),
                teamMembers: [],
                botMembers: [],
                viewerId: viewer.id,
            }),
        ).toEqual([counterpart]);
    });
});
