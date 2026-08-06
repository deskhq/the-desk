/**
 * The message vocabulary, as the server declares it.
 *
 * Everything here that names a server payload is an **alias** of a generated
 * `App.Data.*` type — the DTO in `app/Data/` is the wire contract, and a
 * hand-written copy of one is a defect (ADR-0013). The prose that explains a
 * payload lives on its PHP DTO, so both sides of the wire read the same
 * sentence; what stays here is what the *client* invents on top.
 */

/**
 * The display identity one message asked to be shown under — an incoming webhook
 * posting as a logical source of its own.
 *
 * Render it only through the `@/lib/authorIdentity` helpers: an overridden name
 * may only appear where its bot marker appears with it.
 */
export type AuthorOverride = App.Data.AuthorOverrideData;

/**
 * Whoever a message renders under: its author, a channel reader, a DM
 * counterpart. The full user payload, so a row can draw the avatar, the bot
 * badge, the status emoji and the presence dot without a second lookup.
 */
export type MessageAuthor = App.Data.UserData;

/**
 * A message's kind. `standard` is an ordinary user-authored message and `poll` is
 * an interactive poll card; every other type is an inert system notice, which the
 * timeline renders as a centered, localized line rather than a chat bubble and
 * which never carries interactions or advances unread badges.
 */
export type MessageType = App.Enums.MessageType;

/**
 * A team member referenced by an `@mention` in a message body, and the same
 * compact shape every "who did this" list rides on — reactors, pinner, thread
 * participants, a DM counterpart.
 */
export type Mention = App.Data.MentionData;

/**
 * A member of a channel's roster, as the masthead facepile and the composer's
 * `@mention` autocomplete read them. Composed on the client from the workspace
 * roster and the channel's own bots rather than shipped whole — see
 * `lib/channelRoster.ts`.
 *
 * The full user payload rather than a {@link Mention}: the facepile badges bots
 * and the autocomplete drops them, neither of which a mention payload can answer.
 * Kept distinct from `Mention` on purpose — the two are different server payloads,
 * and one type spanning both is how `isBot` came to be optional on a shape that
 * never carried it.
 */
export type RosterMember = App.Data.UserData;

/**
 * A channel member's read position, powering the "Seen by" affordance. The
 * channel page seeds these from a prop and keeps them current from the
 * `MessageRead` broadcast.
 */
export type ChannelReader = App.Data.ChannelReaderData;

/** A compact quote of the parent message an inline reply answers. */
export type MessageReply = App.Data.MessageReplyData;

/**
 * A compact quote of a forwarded source message — like a {@link MessageReply} but
 * also naming the source channel, for the "Forwarded from #name" attribution.
 */
export type MessageForward = App.Data.MessageForwardData;

/**
 * An unfurled link preview attached to a message. A `pending` card renders as a
 * skeleton until the queued unfurl broadcasts the resolved one in its place.
 */
export type MessagePreview = App.Data.LinkPreviewData;

/**
 * A message's reactions for a single emoji. Viewer-free — the client derives
 * whether it reacted by checking its own id against `reactors` — so it rides the
 * `MessageReactionChanged` broadcast unchanged.
 */
export type Reaction = App.Data.ReactionData;

/**
 * A message's pin to its channel, or the shape of one. Patched live in place from
 * the `MessagePinned` broadcast.
 */
export type MessagePin = App.Data.PinData;

/** One option of a poll, with its tally and (for a public poll) its roster. */
export type PollOption = App.Data.PollOptionData;

/**
 * A poll carried by a `poll`-type message. Viewer-free, so it rides the
 * `PollVoteChanged` broadcast unchanged.
 */
export type Poll = App.Data.PollData;

/**
 * A post in a channel — the payload every timeline, thread, search hit and
 * realtime broadcast is built from.
 */
export type Message = App.Data.MessageData;

/**
 * A message the viewer has scheduled for future delivery to the channel. The
 * channel page lists the viewer's own pending rows in the `scheduledMessages`
 * prop.
 */
export type ScheduledMessage = App.Data.ScheduledMessageData;

/**
 * A personal "remind me about this" reminder on a message. The workspace shares
 * the viewer's pending rows in `reminders` and the due-and-unacknowledged ones in
 * `firedReminders`.
 */
export type MessageReminder = App.Data.MessageReminderData;

/**
 * An open thread's root message. Mirrors the `thread` prop the channel page loads
 * on demand from `?thread=`; the replies ride a separate, paginated
 * `threadReplies` scroll prop (a {@link MessagePage}).
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
 * A single message-search match: the matched message, its highlighted snippet,
 * and the channel + owning team it belongs to.
 */
export type MessageSearchResult = App.Data.MessageSearchResultData;

/**
 * The criteria a set of search matches was selected by, as the client writes them
 * onto the URL. Not the panel's state — the URL is that — but its receipt: the
 * panel compares this against the URL it is looking at to tell whether the matches
 * it holds still answer it. Absent facets arrive as `null`, and the scope is
 * always spelled out.
 */
export type MessageSearchCriteria = {
    q: string;
    from: string | null;
    in: string | null;
    after: string | null;
    before: string | null;
    has: string | null;
    scope: string;
};

/**
 * A channel in the union the Search panel's channel facet offers in "All
 * workspaces" mode. Carries its owning team so the picker can tell same-named
 * channels in different workspaces apart.
 */
export type SearchWorkspaceChannel = {
    id: string;
    name: string;
    slug: string;
    visibility: string;
    teamName: string;
    teamSlug: string;
};

/**
 * A card in the Threads panel: a followed thread's root message plus the
 * conversation it lives in, for rendering the card and its jump-to-thread link.
 */
export type ThreadInboxItem = App.Data.ThreadInboxItemData;

/**
 * The paginated shape delivered by `Inertia::scroll()` for the Threads inbox.
 * `data` arrives newest-activity first; older threads page in on scroll.
 */
export type ThreadInboxPage = {
    data: ThreadInboxItem[];
    next_cursor: string | null;
    prev_cursor: string | null;
};
