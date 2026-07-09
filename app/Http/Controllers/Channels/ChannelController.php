<?php

namespace App\Http\Controllers\Channels;

use App\Actions\Channels\ArchiveChannel;
use App\Actions\Channels\CreateChannel;
use App\Actions\Channels\JoinChannel;
use App\Actions\Channels\MarkChannelRead;
use App\Actions\Channels\MarkThreadRead;
use App\Data\ChannelData;
use App\Data\MessageData;
use App\Data\UserData;
use App\Enums\ChannelVisibility;
use App\Enums\NotificationLevel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Channels\CreateChannelRequest;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Team;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ChannelController extends Controller
{
    /**
     * How many messages newer than a jump target to keep loaded below it, so a
     * jumped-to message shows following context and is not pinned to the bottom.
     */
    private const int JUMP_CONTEXT = 15;

    /**
     * How many messages the initial timeline window loads. Mirrors the page size
     * of {@see Builder::cursorPaginate()} below and
     * feeds the read-boundary window math.
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

    /**
     * Redirect a bare team URL to the team's #general channel.
     */
    public function index(Team $team): RedirectResponse
    {
        return to_route('channels.show', [
            'team' => $team->slug,
            'channel' => Channel::GENERAL_SLUG,
        ]);
    }

    /**
     * Store a newly created channel and redirect to it.
     */
    public function store(CreateChannelRequest $request, Team $team, CreateChannel $createChannel): RedirectResponse
    {
        $channel = $createChannel->handle(
            team: $team,
            name: $request->validated('name'),
            visibility: ChannelVisibility::from($request->validated('visibility')),
            creator: $request->user(),
            topic: $request->validated('topic'),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Channel created.')]);

        return to_route('channels.show', ['team' => $team->slug, 'channel' => $channel->slug]);
    }

    /**
     * Show a channel. The channel sidebar is fed by the globally-shared `channels` prop.
     */
    public function show(Request $request, Team $team, Channel $channel): Response
    {
        Gate::authorize('view', $channel);

        // Surface the current user's own notification preferences on the channel
        // so the header settings menu opens on the persisted state. A non-member
        // viewing a public channel has no pivot row, so the DTO defaults apply.
        $membership = $channel->channelMembers()->where('user_id', $request->user()->id)->first();
        $channel->setAttribute('muted', $membership->muted ?? false);
        $channel->setAttribute('notification_level', $membership?->notification_level->value ?? NotificationLevel::All->value);
        // Surface the member's saved draft so the composer restores it on open; a
        // non-member has no pivot row, so the composer opens empty.
        $channel->setAttribute('draft', $membership?->draft);

        // When arriving from a search result the URL carries the target message
        // id. If it belongs to this channel, cap the initial window a few messages
        // above the target so it loads with context on both sides; the client
        // scrolls to and highlights it, and older history still pages in above via
        // InfiniteScroll. Absent a jump, anchor the window around the read pointer
        // so a channel with more unread than a page holds still loads the
        // "New messages" boundary rather than freezing it at the oldest loaded row.
        $jumpToMessageId = $this->resolveJumpTarget($request, $channel);
        $windowCeilingId = $jumpToMessageId !== null
            ? $this->jumpWindowCeiling($channel, $jumpToMessageId)
            : $this->unreadWindowCeiling($channel, $membership?->last_read_message_id);

        return Inertia::render('channels/Show', [
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'slug' => $team->slug,
            ],
            'channel' => ChannelData::fromChannel($channel),
            // Drives the header's archive control; authoritative so the button
            // only appears for a creator or Admin+ on a non-#general channel.
            'canArchive' => Gate::allows('archive', $channel),
            // Gates the notification settings menu; only a member of the channel
            // has preferences to manage.
            'canManagePreferences' => Gate::allows('updatePreference', $channel),
            // Selectable notification levels for the settings menu.
            'notificationLevels' => NotificationLevel::options(),
            // The message the client should scroll to and highlight on load, or
            // null for a normal channel visit.
            'jumpToMessageId' => $jumpToMessageId,
            // The viewer's read pointer captured at render time, before the
            // client's debounced MarkChannelRead advances it. Drives the
            // "New messages" divider so it lands at the last-read boundary on
            // open; null when the channel has never been read.
            'lastReadMessageId' => $membership?->last_read_message_id !== null ? (string) $membership->last_read_message_id : null,
            // The open thread's root message, resolved from the `?thread=` query
            // param, or null for a normal visit. The client opens a thread by
            // visiting `?thread=<root>`, which also drives the paginated replies
            // below; the closure returns null cheaply when no thread is requested.
            'thread' => fn () => $this->threadPayload($request, $channel),
            // The open thread's replies, oldest last, paginated so a very long
            // thread doesn't ship in one payload. Its own cursor name keeps it
            // independent of the main timeline's, and the client's reverse
            // InfiniteScroll pages older replies in above as it scrolls up.
            'threadReplies' => Inertia::scroll(fn () => $this->threadRepliesPage($request, $channel)),
            // Team members feed the composer's @mention autocomplete; mentions are
            // scoped to the team, never limited to the current channel's members.
            'members' => UserData::collect($team->members()->orderBy('name')->get()),
            // Newest 50 first; the InfiniteScroll composer runs in reverse mode, so
            // scrolling up appends older pages and the client reverses for display.
            // Deleted rows are kept (withTrashed) so the client can render a
            // "message deleted" tombstone in place; MessageData blanks their body.
            'messages' => Inertia::scroll(fn () => $this->mainTimeline($channel->messages()->withTrashed()->getQuery())
                ->withThreadReadState($request->user())
                ->with(['user', 'mentionedUsers', 'replyTo.user', 'replyTo.mentionedUsers', 'threadParticipants'])
                ->when($windowCeilingId, fn (Builder $query) => $query->where('id', '<=', $windowCeilingId))
                ->orderByDesc('id')
                ->cursorPaginate(self::MESSAGE_PAGE_SIZE)
                ->through(fn (Message $message) => MessageData::fromMessage($message))),
        ]);
    }

    /**
     * Resolve the open thread's root from the `?thread=` query param.
     *
     * Returns the root message (annotated with the viewer's thread read-state)
     * when the param names a live root in this channel, or null when it is absent
     * or points elsewhere. The replies are delivered separately and paginated by
     * {@see self::threadRepliesPage()}.
     *
     * @return array{root: MessageData}|null
     */
    private function threadPayload(Request $request, Channel $channel): ?array
    {
        $root = $this->resolveThreadRoot($request, $channel);

        if ($root === null) {
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
    private function threadRepliesPage(Request $request, Channel $channel): CursorPaginator
    {
        $root = $this->resolveThreadRoot($request, $channel);

        $query = $root !== null
            ? $root->threadReplies()->withTrashed()->with(['user', 'mentionedUsers', 'replyTo.user', 'replyTo.mentionedUsers'])
            : Message::query()->whereRaw('1 = 0');

        return $query
            ->orderByDesc('id')
            ->cursorPaginate(self::THREAD_PAGE_SIZE, ['*'], 'thread_cursor')
            ->through(fn (Message $message) => MessageData::fromMessage($message));
    }

    /**
     * Resolve the live root message named by the `?thread=` query param, or null.
     *
     * The eager-load set mirrors the main timeline so the root renders its quote
     * and thread affordances identically.
     */
    private function resolveThreadRoot(Request $request, Channel $channel): ?Message
    {
        $rootId = $request->query('thread');

        if (! is_string($rootId) || $rootId === '') {
            return null;
        }

        return $channel->messages()
            ->whereNull('thread_root_id')
            ->withThreadReadState($request->user())
            ->with(['user', 'mentionedUsers', 'replyTo.user', 'replyTo.mentionedUsers', 'threadParticipants'])
            ->find($rootId);
    }

    /**
     * Resolve the `?message=` jump target to an id belonging to this channel.
     *
     * Returns the message id when it identifies a message in the channel
     * (soft-deleted rows included), or null when the parameter is absent or
     * points at a message from another channel.
     */
    private function resolveJumpTarget(Request $request, Channel $channel): ?string
    {
        $messageId = $request->query('message');

        if (! is_string($messageId) || $messageId === '') {
            return null;
        }

        return $channel->messages()->withTrashed()->whereKey($messageId)->exists()
            ? $messageId
            : null;
    }

    /**
     * Resolve the id that caps the initial message window for a jump.
     *
     * Returns the id of the message JUMP_CONTEXT positions newer than the target
     * so it loads with following context below it rather than pinned to the very
     * bottom of the view. Returns null when fewer than that many newer messages
     * exist — the target is then already near the newest, so no cap is needed.
     */
    private function jumpWindowCeiling(Channel $channel, string $targetId): ?string
    {
        return $channel->messages()
            ->withTrashed()
            ->where('id', '>', $targetId)
            ->orderBy('id')
            ->offset(self::JUMP_CONTEXT - 1)
            ->value('id');
    }

    /**
     * Resolve the id that caps the initial window so the "New messages" boundary
     * loads, or null to keep the default "open at newest" window.
     *
     * The boundary sits at the first message newer than the viewer's read
     * pointer. When few enough messages sit at or after it to fit inside the
     * newest page, the boundary already loads and no window is needed. A busier
     * channel is anchored: the window is capped UNREAD_CONTEXT read messages
     * above the boundary so it opens with the "New messages" line near the top
     * and unread history below, while older history still pages in above via
     * InfiniteScroll.
     *
     * A never-read channel (null pointer) has no last-read boundary to anchor,
     * so it keeps the default "open at newest" window rather than landing the
     * viewer on the oldest message of a long backlog.
     */
    private function unreadWindowCeiling(Channel $channel, ?string $lastReadMessageId): ?string
    {
        if ($lastReadMessageId === null) {
            return null;
        }

        $firstUnreadId = $this->mainTimeline($channel->messages()->withTrashed()->getQuery())
            ->where('id', '>', $lastReadMessageId)
            ->orderBy('id')
            ->value('id');

        if ($firstUnreadId === null) {
            return null;
        }

        // The boundary already loads when the messages at or after it fit in the
        // newest page, so keep opening at newest for read/lightly-unread channels.
        $atOrAfterBoundary = $this->mainTimeline($channel->messages()->withTrashed()->getQuery())
            ->where('id', '>=', $firstUnreadId)
            ->count();

        if ($atOrAfterBoundary <= self::MESSAGE_PAGE_SIZE) {
            return null;
        }

        return $this->mainTimeline($channel->messages()->withTrashed()->getQuery())
            ->where('id', '>', $firstUnreadId)
            ->orderBy('id')
            ->offset(self::MESSAGE_PAGE_SIZE - self::UNREAD_CONTEXT - 1)
            ->value('id');
    }

    /**
     * Constrain a message query to the main channel timeline: top-level messages
     * plus thread replies explicitly "also sent to channel". Thread replies
     * otherwise live only in the thread view.
     *
     * @param  Builder<Message>  $query
     * @return Builder<Message>
     */
    private function mainTimeline(Builder $query): Builder
    {
        return $query->where(fn (Builder $inner) => $inner->whereNull('thread_root_id')->orWhere('sent_to_channel', true));
    }

    /**
     * List public channels in the team the current user can still join.
     */
    public function browse(Request $request, Team $team): Response
    {
        $channels = $team->channels()
            ->where('visibility', ChannelVisibility::Public)
            ->whereNull('archived_at')
            ->whereDoesntHave('channelMembers', fn ($query) => $query->where('user_id', $request->user()->id))
            ->orderBy('name')
            ->get();

        return Inertia::render('channels/Browse', [
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'slug' => $team->slug,
            ],
            'joinableChannels' => ChannelData::collect($channels),
        ]);
    }

    /**
     * Join a public channel and redirect to it.
     */
    public function join(Request $request, Team $team, Channel $channel, JoinChannel $joinChannel): RedirectResponse
    {
        Gate::authorize('join', $channel);

        $joinChannel->handle($channel, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Joined #:channel.', ['channel' => $channel->name])]);

        return to_route('channels.show', ['team' => $team->slug, 'channel' => $channel->slug]);
    }

    /**
     * Mark the channel read for the current user, clearing its sidebar badges.
     *
     * Called by the open channel view (debounced, on focus), so it redirects
     * back and lets Inertia recompute the shared `channels` prop.
     */
    public function read(Request $request, Team $team, Channel $channel, MarkChannelRead $markChannelRead): RedirectResponse
    {
        Gate::authorize('view', $channel);

        $markChannelRead->handle($channel, $request->user());

        return back();
    }

    /**
     * Mark a thread read for the current user, clearing its unread dot.
     *
     * Advances the viewer's per-thread pointer to the thread's latest reply,
     * independently of the channel's read pointer. Called by the open thread
     * panel (debounced, on focus). The `{message}` binding resolves the root,
     * including a soft-deleted tombstone root whose thread is still readable.
     */
    public function readThread(Request $request, Team $team, Channel $channel, Message $message, MarkThreadRead $markThreadRead): RedirectResponse
    {
        Gate::authorize('view', $channel);

        $markThreadRead->handle($message, $request->user());

        return back();
    }

    /**
     * Archive a channel and redirect to the team's #general channel.
     *
     * The archived channel becomes read-only and drops out of the active
     * sidebar, so we send the user back to #general rather than to a channel
     * that no longer appears in their list.
     */
    public function archive(Request $request, Team $team, Channel $channel, ArchiveChannel $archiveChannel): RedirectResponse
    {
        Gate::authorize('archive', $channel);

        $archiveChannel->handle($channel);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Archived #:channel.', ['channel' => $channel->name])]);

        return to_route('channels.index', ['team' => $team->slug]);
    }
}
