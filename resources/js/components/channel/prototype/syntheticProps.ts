/**
 * PROTOTYPE — throwaway (#1244).
 *
 * The synthetic masthead props an Inertia `instant` visit could paint before the
 * response lands, derived only from what the client already holds. This mirrors
 * what a real `pageProps: (currentProps, sharedProps) => ...` callback would
 * return, so the prototype is testing the real synthesis rule and not a mockup.
 *
 * Everything here comes from the shared shell:
 * - `channel` is the sidebar's own `ChannelData` for the target, which already
 *   answers name, topic, DM participants, star, mute and notification level.
 * - `members` is the shared `teamMembers` prop. It is *typed* `PersonRef[]` on
 *   the client but the server ships full `UserData`, which is why the facepile
 *   paints for real rather than as initials.
 *
 * What has no honest source is defaulted conservatively: every permission reads
 * false (so no destructive affordance can be painted for a channel whose rules
 * we have not been told), and the pin count reads zero.
 */
import { notificationIndicator } from '@/lib/notificationIndicator';
import type { Channel, RosterMember } from '@/types';

export type SyntheticMasthead = {
    channel: Channel;
    members: RosterMember[];
    title: string;
    canManagePreferences: boolean;
    canArchive: boolean;
    canDelete: boolean;
    canLeave: boolean;
    canAddPeople: boolean;
    notificationLevels: [];
    starred: boolean;
    muted: boolean;
    pinCount: number;
    notificationLevel: Channel['notificationLevel'];
    notificationStatus: ReturnType<typeof notificationIndicator>;
};

export function buildSyntheticMasthead(
    channel: Channel,
    teamMembers: RosterMember[],
): SyntheticMasthead {
    return {
        channel,
        members: teamMembers,
        title: channel.name,
        canManagePreferences: false,
        canArchive: false,
        canDelete: false,
        canLeave: false,
        canAddPeople: false,
        notificationLevels: [],
        starred: channel.starred,
        muted: channel.muted,
        pinCount: 0,
        notificationLevel: channel.notificationLevel,
        notificationStatus: notificationIndicator(
            channel.muted,
            channel.notificationLevel,
        ),
    };
}
