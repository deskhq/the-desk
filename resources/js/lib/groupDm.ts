import { translate } from '@/lib/i18n';
import type { Channel, DmParticipant } from '@/types/channels';

/**
 * A participant's first name — the leading whitespace-separated token — used for
 * the compact participant-based names the sidebar and masthead render. Falls
 * back to the whole (trimmed) string when there is no whitespace.
 */
export function firstName(name: string): string {
    return name.trim().split(/\s+/u)[0] ?? '';
}

/**
 * The sidebar row's participant name for a group DM.
 *
 * Shows every participant's first name when the group is small enough
 * (`maxNames` or fewer), otherwise the first `maxNames - 1` names followed by a
 * "+N" overflow so the row never overflows its width — e.g. "Jonas, Ana, Tomas"
 * for three, "Maya, Jonas, +2" for four.
 */
export function groupDmSidebarName(
    participants: DmParticipant[],
    maxNames = 3,
): string {
    const names = participants.map((participant) =>
        firstName(participant.name),
    );

    if (names.length <= maxNames) {
        return names.join(', ');
    }

    const shown = names.slice(0, maxNames - 1);
    const overflow = names.length - shown.length;

    return translate(':shown, +:overflow', {
        shown: shown.join(', '),
        overflow: overflow.toString(),
    });
}

/**
 * The masthead's participant name for a group DM: every participant's first
 * name, the last joined with an ampersand — "Jonas, Ana & Tomas". A single
 * participant is just their name; an empty set (a group everyone else left)
 * yields an empty string, which the caller replaces with a generic label.
 */
export function groupDmMastheadName(participants: DmParticipant[]): string {
    const names = participants.map((participant) =>
        firstName(participant.name),
    );

    if (names.length <= 1) {
        return names.join('');
    }

    const head = names.slice(0, -1);
    const tail = names[names.length - 1];

    return translate(':head & :tail', { head: head.join(', '), tail });
}

/**
 * A canonical signature for a direct-message member set: the sorted, colon-joined
 * unique user ids. The same set of people always yields the same signature
 * regardless of order, so two conversations can be compared for "same members".
 */
export function directMessageSignature(ids: string[]): string {
    return [...new Set(ids)].sort().join(':');
}

/**
 * The signature of an existing DM channel's full member set, reconstructed from
 * the viewer plus the channel's other participants. Null for a channel that
 * carries no participant data (a standard channel).
 */
export function channelSignature(
    channel: Pick<Channel, 'dmParticipants'>,
    viewerId: string,
): string | null {
    if (channel.dmParticipants == null) {
        return null;
    }

    return directMessageSignature([
        viewerId,
        ...channel.dmParticipants.map((participant) => participant.id),
    ]);
}

/**
 * Find an existing direct message whose member set exactly matches `targetIds`,
 * excluding the conversation the add-people flow started from. Drives the modal's
 * "this conversation already exists" preview so the same set reuses it rather
 * than appearing to spawn a duplicate. Returns undefined when none matches.
 */
export function findMatchingDirectMessage(
    channels: Channel[],
    targetIds: string[],
    excludeChannelId: string,
    viewerId: string,
): Channel | undefined {
    const target = directMessageSignature(targetIds);

    return channels.find(
        (channel) =>
            channel.id !== excludeChannelId &&
            channelSignature(channel, viewerId) === target,
    );
}
