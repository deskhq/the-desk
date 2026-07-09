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
};
