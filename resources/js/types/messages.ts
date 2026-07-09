export type MessageAuthor = {
    id: string;
    name: string;
};

/**
 * A team member referenced by an `@mention` in a message body. Mirrors the
 * `MentionData` DTO and rides on every MessageData payload.
 */
export type Mention = {
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
    mentions: Mention[];
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

/**
 * A single message-search match. Mirrors the `MessageSearchResultData` DTO:
 * the matched message plus the channel it belongs to, for rendering the result
 * row and building its jump-to-message link.
 */
export type MessageSearchResult = {
    message: Message;
    channelName: string;
    channelSlug: string;
};
