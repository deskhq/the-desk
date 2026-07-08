export type Channel = {
    id: string;
    name: string;
    slug: string;
    visibility: string;
    topic: string | null;
    isGeneral: boolean;
    isArchived: boolean;
    hasUnread?: boolean;
};
