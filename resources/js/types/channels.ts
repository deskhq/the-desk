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
};
