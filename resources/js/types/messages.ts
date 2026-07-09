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

/**
 * A compact quote of the parent message an inline reply answers. Mirrors the
 * `MessageReplyData` DTO: flat (never nested) so a quote can't recurse, with
 * the body and mentions blanked when the parent has been deleted.
 */
export type MessageReply = {
    id: string;
    body: string;
    authorName: string;
    isDeleted: boolean;
    mentions: Mention[];
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
    replyTo: MessageReply | null;
    /**
     * Threading fields (mirror the `MessageData` DTO). `threadRootId` is set on a
     * thread reply and names its root; null on a root/normal message. The
     * `thread*` aggregates are populated on a root so the timeline can render its
     * "N replies" affordance, and survive a soft delete. `sentToChannel` marks a
     * reply that was also surfaced in the main timeline.
     */
    threadRootId: string | null;
    sentToChannel: boolean;
    threadReplyCount: number;
    threadLastReplyAt: string | null;
    threadParticipants: Mention[];
};

/**
 * An open thread: its root message plus every reply (oldest first, tombstones
 * included). Mirrors the `thread` prop the channel page loads on demand.
 */
export type Thread = {
    root: Message;
    replies: Message[];
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
