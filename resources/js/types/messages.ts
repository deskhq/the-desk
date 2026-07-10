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
 * A channel member's read position, powering the "Seen by" affordance. Mirrors
 * the `ChannelReaderData` DTO: the member and the id of the last message they
 * have read (null when they have never read the channel). The channel page seeds
 * these from a prop and keeps them current from the `MessageRead` broadcast.
 */
export type ChannelReader = {
    user: MessageAuthor;
    lastReadMessageId: string | null;
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

/**
 * A compact quote of a forwarded source message. Mirrors the
 * `MessageForwardData` DTO: like `MessageReply` but also names the source
 * channel so the forward can render its "Forwarded from #name" attribution.
 * Flat (never nested), with the body and mentions blanked when the source has
 * been deleted.
 */
export type MessageForward = {
    id: string;
    body: string;
    authorName: string;
    channelName: string;
    isDeleted: boolean;
    mentions: Mention[];
};

/**
 * An unfurled link preview attached to a message. Mirrors the `LinkPreviewData`
 * DTO: `pending` while the queued job fetches Open Graph metadata (the card
 * renders as a skeleton) and `ready` once it resolves; failed previews are
 * dropped server-side so they never reach the client. The metadata fields are
 * null until the preview is ready (and stay null for any tag the page omits).
 */
export type MessagePreview = {
    url: string;
    status: 'pending' | 'ready' | 'failed';
    title: string | null;
    description: string | null;
    imageUrl: string | null;
    siteName: string | null;
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
    /**
     * Open Graph preview cards for the URLs in the body (mirrors the
     * `MessageData` DTO's `linkPreviews`), in order of appearance. Empty when the
     * message has no links; a `pending` entry renders as a skeleton until the
     * queued unfurl broadcasts the resolved card in place.
     */
    linkPreviews: MessagePreview[];
    replyTo: MessageReply | null;
    /**
     * A compact quote of the message this one forwards into the channel, or null
     * for a normal message. Mirrors the `MessageData` DTO's `forwardedFrom`.
     */
    forwardedFrom: MessageForward | null;
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
    /**
     * Per-viewer thread read-state (mirror the `MessageData` DTO), meaningful on
     * a root. `threadFollowed` is the Slack-style auto-follow signal — the viewer
     * authored the root, replied, or was mentioned in the thread — and gates
     * whether a live reply raises the dot. `threadUnread` drives the dot on the
     * root's "N replies" affordance and clears when the thread is read. Broadcast
     * payloads omit viewer context, so the client preserves its own values across
     * patches rather than taking the server's defaults.
     */
    threadFollowed: boolean;
    threadUnread: boolean;
};

/**
 * An open thread's root message. Mirrors the `thread` prop the channel page
 * loads on demand from `?thread=`; the replies ride a separate, paginated
 * `threadReplies` scroll prop (a `MessagePage`).
 */
export type Thread = {
    root: Message;
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

/**
 * A row in the Threads inbox. Mirrors the `ThreadInboxItemData` DTO: a followed
 * thread's root message (carrying its reply count, participants, and per-viewer
 * `threadUnread` state) plus the channel it lives in, for rendering the row and
 * its jump-to-thread link.
 */
export type ThreadInboxItem = {
    root: Message;
    channelName: string;
    channelSlug: string;
};

/**
 * The paginated shape delivered by `Inertia::scroll()` for the Threads inbox.
 * `data` arrives newest-activity first; older threads page in on scroll.
 */
export type ThreadInboxPage = {
    data: ThreadInboxItem[];
    next_cursor: string | null;
    prev_cursor: string | null;
};
