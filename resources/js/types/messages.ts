export type MessageAuthor = {
    id: string;
    name: string;
};

export type Message = {
    id: string;
    clientUuid: string;
    body: string;
    user: MessageAuthor;
    createdAt: string;
    editedAt: string | null;
    isDeleted: boolean;
};

/**
 * The paginated shape delivered by `Inertia::scroll()` for the message list.
 * `data` arrives newest-first; the client reverses it for display.
 */
export type MessagePage = {
    data: Message[];
    next_cursor: string | null;
    prev_cursor: string | null;
};
