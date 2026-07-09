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
};

// A user-created sidebar section, rendered between "Starred" and the default
// "Channels" group. Mirrors `App\Data\ChannelSectionData`.
export type ChannelSection = {
    id: string;
    name: string;
    position: number;
    collapsed: boolean;
};
