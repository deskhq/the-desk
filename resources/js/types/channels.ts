// A DM participant surfaced to the sidebar / masthead for the avatar stack and
// participant-based name. Mirrors `App\Data\UserData`.
export type DmParticipant = App.Data.UserData;

export type NotificationLevel = 'all' | 'mentions' | 'nothing';

export type NotificationLevelOption = {
    value: NotificationLevel;
    label: string;
};

export type Channel = {
    id: string;
    name: string;
    slug: string;
    visibility: string;
    topic: string | null;
    isGeneral: boolean;
    isArchived: boolean;
    muted: boolean;
    notificationLevel: NotificationLevel;
    unreadCount: number;
    mentionCount: number;
    // Whether the viewer has unsent composer text saved for this channel; drives
    // the sidebar's draft cue. The full `draft` text is only present on the open
    // channel, so the composer can restore it.
    hasDraft: boolean;
    draft: string | null;
    // Whether the viewer has starred (favorited) this channel, pinning it to the
    // sidebar's "Starred" section.
    starred: boolean;
    // The custom section the viewer has filed this channel under, or null for the
    // default "Channels" group. Starred channels render in "Starred" regardless.
    sectionId: string | null;
    // The channel's manual order within whichever sidebar group it renders in;
    // ties fall back to the alphabetical order the server applies.
    position: number;
    // Whether this channel is a direct message — a 1:1 or a group. DMs render in
    // the dedicated "Direct messages" sidebar group with a viewer-relative name
    // and avatar instead of in the channel sections.
    isDirect: boolean;
    // Whether this DM is a group conversation (3+ participants). A group renders
    // an avatar stack + participant-joined name instead of the single other
    // participant, and supports "Add people" / "Leave conversation".
    isGroupDirect: boolean;
    // For a 1:1 DM, the id of the participant the viewer sees (the other member,
    // or themselves in a self-DM — labelled "You"); null for a group DM or a
    // standard channel. Keys the presence dot and avatar.
    dmUserId: string | null;
    // For a DM, the other participants (viewer excluded), ordered by name — the
    // single other member of a 1:1, or the group's members. Empty for a self-DM,
    // null for a standard channel. Drives the avatar stack, the participant-based
    // name, and the same-member-set dedup check.
    dmParticipants: DmParticipant[] | null;
    // ISO-8601 timestamp of the channel's most recent activity (latest message,
    // falling back to when the channel was created), used to order the "Direct
    // messages" group by recency.
    lastActivityAt: string | null;
};

// A user-created sidebar section, rendered between "Starred" and the default
// "Channels" group. Mirrors `App\Data\ChannelSectionData`.
export type ChannelSection = {
    id: string;
    name: string;
    position: number;
    collapsed: boolean;
};
