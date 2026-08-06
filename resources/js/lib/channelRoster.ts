import type { Channel, RosterMember } from '@/types';

/** What the open channel's roster is composed from. */
export interface ChannelRosterSources {
    /** The open channel, for whether it is a DM and who is in it. */
    channel: Pick<Channel, 'isDirect' | 'dmParticipants'>;
    /** The workspace roster the shell already holds. */
    teamMembers: RosterMember[];
    /** The channel's own bots, the one part of the roster the page ships. */
    botMembers: RosterMember[];
    /** The viewer, who is on the team roster and in every DM they can open. */
    viewerId: string;
}

/**
 * The channel's roster, as the masthead facepile and the composer's `@mention`
 * autocomplete read it — composed on the client rather than serialised again by
 * the server.
 *
 * A standard channel is team-scoped, so its roster is the workspace roster the
 * shell already holds plus the channel's own bots, which are channel members
 * rather than team members and so appear in no other prop. A direct message is
 * scoped to its own participants, which ride the channel itself: composing it
 * from the team roster instead would drop anyone who has since left the team,
 * and they are still in the conversation.
 *
 * Shipping the whole roster as a page prop made it byte-identical to the shell's
 * `teamMembers` on every standard channel visit — the same fact serialised twice
 * in one response (#1254).
 */
export function channelRoster(sources: ChannelRosterSources): RosterMember[] {
    if (!sources.channel.isDirect) {
        return [...sources.teamMembers, ...sources.botMembers];
    }

    const viewer = sources.teamMembers.find(
        (member) => member.id === sources.viewerId,
    );

    const roster = [...(sources.channel.dmParticipants ?? [])];

    if (viewer !== undefined) {
        roster.push(viewer);
    }

    return roster.sort((one, other) => one.name.localeCompare(other.name));
}
