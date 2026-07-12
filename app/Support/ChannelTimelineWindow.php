<?php

namespace App\Support;

use App\Data\MessageData;
use App\Http\Controllers\Channels\ChannelController;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Resolves where a channel's initial message window opens and assembles the
 * timeline and thread payloads a channel view renders.
 *
 * This is the read-model behind {@see ChannelController::show()}:
 * the most regression-prone logic in the app — unread-boundary anchoring, jump
 * context, and page-size arithmetic. It takes explicit parameters (no `Request`)
 * so the window math is unit-testable without an HTTP round-trip. The controller
 * keeps HTTP glue only: resolve params from the request, call this, render.
 *
 * The two request-derived ids (`$requestedJumpId`, `$requestedThreadRootId`)
 * arrive raw from query params, so they are typed `mixed` and validated here —
 * a tampered non-string or a message from another channel resolves to null.
 */
class ChannelTimelineWindow
{
    /**
     * How many messages newer than a jump target to keep loaded below it, so a
     * jumped-to message shows following context and is not pinned to the bottom.
     */
    private const int JUMP_CONTEXT = 15;

    /**
     * How many messages the initial timeline window loads. Mirrors the page size
     * of the cursor paginator below and feeds the read-boundary window math.
     */
    private const int MESSAGE_PAGE_SIZE = 50;

    /**
     * How many replies a thread page loads. The panel pages older replies in on
     * scroll-up, mirroring the main timeline.
     */
    private const int THREAD_PAGE_SIZE = 50;

    /**
     * How many already-read messages to keep loaded above the "New messages"
     * boundary when a channel opens deep enough into unread history that the
     * boundary would otherwise fall outside the initial window.
     */
    private const int UNREAD_CONTEXT = 10;

    private bool $jumpResolved = false;

    private ?string $resolvedJumpId = null;

    private bool $threadRootResolved = false;

    private ?Message $resolvedThreadRoot = null;

    public function __construct(
        private readonly Channel $channel,
        private readonly User $viewer,
        private readonly mixed $requestedJumpId = null,
        private readonly ?string $lastReadMessageId = null,
        private readonly mixed $requestedThreadRootId = null,
    ) {}

    /**
     * The `?message=` jump target resolved to an id belonging to this channel.
     *
     * Returns the id when it identifies a message in the channel (soft-deleted
     * rows included), or null when the parameter is absent, non-string, or points
     * at a message from another channel. Memoised: it also feeds {@see ceilingId()}.
     */
    public function jumpToMessageId(): ?string
    {
        if (! $this->jumpResolved) {
            $this->resolvedJumpId = $this->resolveJumpTarget();
            $this->jumpResolved = true;
        }

        return $this->resolvedJumpId;
    }

    /**
     * The id that caps the initial message window, or null for the default
     * "open at newest" window.
     *
     * A jump anchors the window around its target; otherwise the read pointer
     * anchors it around the "New messages" boundary.
     */
    public function ceilingId(): ?string
    {
        $jumpId = $this->jumpToMessageId();

        return $jumpId !== null
            ? $this->jumpWindowCeiling($jumpId)
            : $this->unreadWindowCeiling();
    }

    /**
     * The main timeline as a cursor-paginated page of {@see MessageData}.
     *
     * Newest first (the client reverses for display and pages older messages in
     * above on scroll-up). Tombstones are kept so deletions render in place; the
     * window ceiling caps the newest loaded row.
     *
     * @return CursorPaginator<int, MessageData>
     */
    public function messages(): CursorPaginator
    {
        $ceilingId = $this->ceilingId();

        return $this->mainTimeline()
            ->withThreadReadState($this->viewer)
            ->withMessageDataRelations()
            ->when($ceilingId, fn (Builder $query) => $query->where('id', '<=', $ceilingId))
            ->orderByDesc('id')
            ->cursorPaginate(self::MESSAGE_PAGE_SIZE)
            ->through(fn (Message $message): MessageData => MessageData::fromMessage($message));
    }

    /**
     * The open thread's root from the `?thread=` query param.
     *
     * Returns `['root' => MessageData]` when the param names a live root in this
     * channel, or null when it is absent or points elsewhere. Replies are
     * delivered separately by {@see threadReplies()}.
     *
     * @return array{root: MessageData}|null
     */
    public function thread(): ?array
    {
        $root = $this->resolveThreadRoot();

        if (! $root instanceof Message) {
            return null;
        }

        return ['root' => MessageData::fromMessage($root)];
    }

    /**
     * The open thread's replies as a cursor-paginated page for infinite scroll.
     *
     * Ordered newest first (the client reverses for display and pages older
     * replies in on scroll-up), tombstones included so deletions render in place.
     * Its own cursor name keeps the pagination independent of the main timeline's.
     * Falls back to an empty page when no live root is named, so the scroll prop
     * always has a well-formed shape even on a normal channel visit.
     *
     * @return CursorPaginator<int, MessageData>
     */
    public function threadReplies(): CursorPaginator
    {
        $root = $this->resolveThreadRoot();

        $query = $root instanceof Message
            ? $root->threadReplies()->withTrashed()->withMessageDataRelations()
            : Message::query()->whereRaw('1 = 0');

        return $query
            ->orderByDesc('id')
            ->cursorPaginate(self::THREAD_PAGE_SIZE, ['*'], 'thread_cursor')
            ->through(fn (Message $message): MessageData => MessageData::fromMessage($message));
    }

    /**
     * Validate the requested jump target against this channel.
     */
    private function resolveJumpTarget(): ?string
    {
        if (! is_string($this->requestedJumpId) || $this->requestedJumpId === '') {
            return null;
        }

        return $this->channel->messages()->withTrashed()->whereKey($this->requestedJumpId)->exists()
            ? $this->requestedJumpId
            : null;
    }

    /**
     * The id that caps the initial window for a jump.
     *
     * Returns the id of the message JUMP_CONTEXT positions newer than the target
     * so it loads with following context below it rather than pinned to the very
     * bottom of the view. Returns null when fewer than that many newer messages
     * exist — the target is then already near the newest, so no cap is needed.
     */
    private function jumpWindowCeiling(string $targetId): ?string
    {
        return $this->channel->messages()
            ->withTrashed()
            ->where('id', '>', $targetId)
            ->orderBy('id')
            ->offset(self::JUMP_CONTEXT - 1)
            ->value('id');
    }

    /**
     * The id that caps the initial window so the "New messages" boundary loads,
     * or null to keep the default "open at newest" window.
     *
     * The boundary sits at the first message newer than the viewer's read
     * pointer. When few enough messages sit at or after it to fit inside the
     * newest page, the boundary already loads and no window is needed. A busier
     * channel is anchored: the window is capped UNREAD_CONTEXT read messages
     * above the boundary so it opens with the "New messages" line near the top
     * and unread history below, while older history still pages in above.
     *
     * A never-read channel (null pointer) has no last-read boundary to anchor,
     * so it keeps the default "open at newest" window rather than landing the
     * viewer on the oldest message of a long backlog.
     */
    private function unreadWindowCeiling(): ?string
    {
        if ($this->lastReadMessageId === null) {
            return null;
        }

        $firstUnreadId = $this->mainTimeline()
            ->where('id', '>', $this->lastReadMessageId)
            ->orderBy('id')
            ->value('id');

        if ($firstUnreadId === null) {
            return null;
        }

        // The boundary already loads when the messages at or after it fit in the
        // newest page, so keep opening at newest for read/lightly-unread channels.
        $atOrAfterBoundary = $this->mainTimeline()
            ->where('id', '>=', $firstUnreadId)
            ->count();

        if ($atOrAfterBoundary <= self::MESSAGE_PAGE_SIZE) {
            return null;
        }

        return $this->mainTimeline()
            ->where('id', '>', $firstUnreadId)
            ->orderBy('id')
            ->offset(self::MESSAGE_PAGE_SIZE - self::UNREAD_CONTEXT - 1)
            ->value('id');
    }

    /**
     * Resolve the live root message named by the `?thread=` query param, or null.
     *
     * The eager-load set mirrors the main timeline so the root renders its quote
     * and thread affordances identically. Memoised: both {@see thread()} and
     * {@see threadReplies()} read it.
     */
    private function resolveThreadRoot(): ?Message
    {
        if ($this->threadRootResolved) {
            return $this->resolvedThreadRoot;
        }

        $this->threadRootResolved = true;

        if (! is_string($this->requestedThreadRootId) || $this->requestedThreadRootId === '') {
            return $this->resolvedThreadRoot;
        }

        return $this->resolvedThreadRoot = $this->channel->messages()
            ->whereNull('thread_root_id')
            ->withThreadReadState($this->viewer)
            ->withMessageDataRelations()
            ->find($this->requestedThreadRootId);
    }

    /**
     * A fresh base query constrained to the main channel timeline: top-level
     * messages plus thread replies explicitly "also sent to channel". Thread
     * replies otherwise live only in the thread view.
     *
     * @return Builder<Message>
     */
    private function mainTimeline(): Builder
    {
        return $this->channel->messages()->withTrashed()->getQuery()
            ->where(fn (Builder $inner) => $inner->whereNull('thread_root_id')->orWhere('sent_to_channel', true));
    }
}
