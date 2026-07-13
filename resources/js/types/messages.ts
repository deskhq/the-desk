export type MessageAuthor = {
    id: string;
    name: string;
};

/**
 * A message's kind (mirrors the `MessageType` enum). `standard` is an ordinary
 * user-authored message; `member_joined` / `member_left` are inert system
 * notices the timeline renders as centered, localized lines rather than chat
 * bubbles, and which never carry interactions or advance unread badges.
 */
export type MessageType = 'standard' | 'member_joined' | 'member_left';

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
    // Null when the source is a direct message (a DM has no name); the client
    // then renders "a direct message" instead of a "#channel" attribution.
    channelName: string | null;
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

/**
 * A message's reactions for a single emoji. Mirrors the `ReactionData` DTO: the
 * emoji, its total count, and the reactor set (id + name, for the "you and 3
 * others" tooltip). The summary is viewer-free — the client derives whether it
 * reacted by checking its own id against `reactors` — so it rides the
 * `MessageReactionChanged` broadcast unchanged and every viewer merges the same
 * payload. Ordered first-reacted first, matching the server's aggregation.
 */
export type Reaction = {
    emoji: string;
    count: number;
    reactors: Mention[];
};

export type Message = {
    id: string;
    clientUuid: string;
    body: string;
    /**
     * The message kind. `standard` for a normal user message; a `member_joined`
     * or `member_left` system notice renders as a centered, inert timeline line
     * (from the `type` and author) and is guarded out of every interaction path.
     */
    type: MessageType;
    user: MessageAuthor;
    createdAt: string;
    editedAt: string | null;
    isDeleted: boolean;
    mentions: Mention[];
    /**
     * Aggregated emoji reactions (mirrors the `MessageData` DTO's `reactions`),
     * one entry per distinct emoji. Empty when nobody has reacted and always
     * empty on a tombstone. Patched live in place from `MessageReactionChanged`.
     */
    reactions: Reaction[];
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
 * A message the viewer has scheduled for future delivery to the channel. Mirrors
 * the `ScheduledMessageData` DTO: the pending body, the UTC `sendAt` instant (the
 * client renders it in the viewer's zone), and the inline reply quote it will
 * carry once delivered. The channel page lists the viewer's own pending rows in
 * the `scheduledMessages` prop.
 */
export type ScheduledMessage = {
    id: string;
    body: string;
    sendAt: string;
    createdAt: string;
    replyTo: MessageReply | null;
};

/**
 * A personal "remind me about this" reminder on a message. Mirrors the
 * `MessageReminderData` DTO: the id, the reminded message (id + author + body +
 * a `isDeleted` stub flag), where it lives (team + channel slug/name for the
 * link back), and the UTC `remindAt` instant the client renders in the viewer's
 * zone. The workspace shares the viewer's pending rows in `reminders` and the
 * due-and-unacknowledged ones in `firedReminders`.
 */
export type MessageReminder = {
    id: string;
    messageId: string;
    remindAt: string;
    teamSlug: string;
    channelSlug: string;
    channelName: string | null;
    authorName: string;
    body: string;
    isDeleted: boolean;
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
