<?php

namespace App\Http\Controllers\Channels;

use App\Actions\Channels\ArchiveChannel;
use App\Actions\Channels\CreateChannel;
use App\Actions\Channels\JoinChannel;
use App\Actions\Channels\LeaveChannel;
use App\Actions\Channels\MarkChannelRead;
use App\Actions\Channels\MarkThreadRead;
use App\Data\ChannelData;
use App\Data\ChannelReaderData;
use App\Data\MessageData;
use App\Data\ScheduledMessageData;
use App\Data\UserData;
use App\Enums\AuditAction;
use App\Enums\ChannelVisibility;
use App\Enums\NotificationLevel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Channels\CreateChannelRequest;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Team;
use App\Support\AuditRecorder;
use App\Support\ChannelTimelineWindow;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ChannelController extends Controller
{
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
    public function store(CreateChannelRequest $request, Team $team, CreateChannel $createChannel, AuditRecorder $recorder): RedirectResponse
    {
        $channel = $createChannel->handle(
            team: $team,
            name: $request->validated('name'),
            visibility: ChannelVisibility::from($request->validated('visibility')),
            creator: $request->user(),
            topic: $request->validated('topic'),
        );

        $recorder->record($team, $request->user(), AuditAction::ChannelCreated, $channel, [
            'channel_name' => $channel->name,
        ]);

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

        // The timeline read-model owns where the initial window opens — jump
        // anchoring, unread-boundary anchoring, page-size arithmetic — and
        // assembles the timeline and thread payloads. It takes explicit params
        // (the raw `?message=` / `?thread=` query values and the read pointer),
        // so this action stays HTTP glue: resolve params, call it, render.
        $window = new ChannelTimelineWindow(
            channel: $channel,
            viewer: $request->user(),
            requestedJumpId: $request->query('message'),
            lastReadMessageId: $membership?->last_read_message_id,
            requestedThreadRootId: $request->query('thread'),
        );

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
            // Gates the "Leave channel" menu item + modal; a member of a standard
            // channel that isn't #general may leave.
            'canLeave' => Gate::allows('leave', $channel),
            // Gates the reaction affordances; only a member of a non-archived
            // channel may react, matching the server-side `postMessage` rule.
            'canReact' => Gate::allows('postMessage', $channel),
            // Whether the viewer already belongs to the channel. A non-member can
            // reach a public channel by URL and read it, but the composer is
            // replaced by a "Join channel" call-to-action until they join.
            'isMember' => $membership !== null,
            // The channel's member count, surfaced in the join call-to-action so a
            // non-member sees how many teammates are already in the channel.
            'memberCount' => $channel->channelMembers()->count(),
            // The channel's pin count, driving the masthead's pins-button badge on
            // first load; later pins/unpins patch it live over MessagePinned.
            'pinCount' => $channel->pins()->count(),
            // The channel's pinned messages, most-recently-pinned first, feeding
            // the pins popover. Each row is a full MessageData (its own `pin`
            // carries the "Pinned by :name" attribution), so the panel reuses the
            // same rendering as the timeline. Bounded by the 100-pin cap.
            'pins' => MessageData::collect(
                Message::query()
                    ->withMessageDataRelations()
                    ->join('message_pins', 'message_pins.message_id', '=', 'messages.id')
                    ->where('message_pins.channel_id', $channel->id)
                    ->orderByDesc('message_pins.created_at')
                    ->orderByDesc('message_pins.id')
                    ->select('messages.*')
                    ->get()
            ),
            // Selectable notification levels for the settings menu.
            'notificationLevels' => NotificationLevel::options(),
            // The message the client should scroll to and highlight on load, or
            // null for a normal channel visit.
            'jumpToMessageId' => $window->jumpToMessageId(),
            // The viewer's read pointer captured at render time, before the
            // client's debounced MarkChannelRead advances it. Drives the
            // "New messages" divider so it lands at the last-read boundary on
            // open; null when the channel has never been read.
            'lastReadMessageId' => $membership?->last_read_message_id !== null ? (string) $membership->last_read_message_id : null,
            // The open thread's root message, resolved from the `?thread=` query
            // param, or null for a normal visit. The client opens a thread by
            // visiting `?thread=<root>`, which also drives the paginated replies
            // below; the closure returns null cheaply when no thread is requested.
            'thread' => $window->thread(...),
            // The open thread's replies, oldest last, paginated so a very long
            // thread doesn't ship in one payload. Its own cursor name keeps it
            // independent of the main timeline's, and the client's reverse
            // InfiniteScroll pages older replies in above as it scrolls up.
            'threadReplies' => Inertia::scroll(fn (): CursorPaginator => $window->threadReplies()),
            // The viewer's own pending scheduled messages for this channel, soonest
            // first, feeding the composer's "Scheduled" affordance. Only pending
            // rows are listed — sent and cancelled ones drop off. The reply quote
            // is eager-loaded so an inline-reply schedule renders its parent.
            'scheduledMessages' => ScheduledMessageData::collect(
                $channel->scheduledMessages()
                    ->pending()
                    ->where('user_id', $request->user()->id)
                    ->with(['replyTo.user', 'replyTo.mentionedUsers'])
                    ->orderBy('send_at')
                    ->get()
            ),
            // Feeds the composer's @mention autocomplete. A standard channel is
            // team-scoped — you may mention anyone on the team. A direct message
            // is scoped to its own participants, since mentioning someone who
            // isn't in the conversation would never reach them (this generalizes
            // to group DMs, whatever their member count).
            'members' => UserData::collect(
                ($channel->isDirectMessage() ? $channel->members() : $team->members())
                    ->orderBy('name')
                    ->get()
            ),
            // Read pointers of the channel's other members who share read receipts,
            // seeding the "Seen by" affordance at open; later advances arrive via the
            // MessageRead broadcast. The viewer and opted-out members are excluded.
            'channelReaders' => ChannelReaderData::collect(
                $channel->channelMembers()
                    ->where('user_id', '!=', $request->user()->id)
                    ->whereRelation('user', 'share_read_receipts', true)
                    ->with('user')
                    ->get()
            ),
            // Newest 50 first; the InfiniteScroll composer runs in reverse mode, so
            // scrolling up appends older pages and the client reverses for display.
            // Deleted rows are kept (withTrashed) so the client can render a
            // "message deleted" tombstone in place; MessageData blanks their body.
            'messages' => Inertia::scroll(fn (): CursorPaginator => $window->messages()),
        ]);
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
     * Leave a channel and redirect to the team's #general channel.
     *
     * The policy rejects leaving #general and 1:1 direct messages (those are
     * closed, not left), so reaching here is a member leaving a standard channel
     * or a group direct message. Either way {@see LeaveChannel} drops the pivot
     * and records a "member left" notice. #general always exists and the leaver
     * is always a member of it, so it is a uniform place to land afterwards.
     */
    public function leave(Request $request, Team $team, Channel $channel, LeaveChannel $leaveChannel): RedirectResponse
    {
        Gate::authorize('leave', $channel);

        $leaveChannel->handle($channel, $request->user());

        // A group direct message has no name, so it gets a conversation-worded
        // confirmation; a standard channel names itself.
        $message = $channel->isDirectMessage()
            ? __('You left the conversation.')
            : __('Left #:channel.', ['channel' => $channel->name]);

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('channels.show', ['team' => $team->slug, 'channel' => Channel::GENERAL_SLUG]);
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
    public function archive(Request $request, Team $team, Channel $channel, ArchiveChannel $archiveChannel, AuditRecorder $recorder): RedirectResponse
    {
        // The policy rejects archiving #general or an already-archived channel,
        // so reaching here always represents a fresh archive worth recording.
        Gate::authorize('archive', $channel);

        $archiveChannel->handle($channel);

        $recorder->record($team, $request->user(), AuditAction::ChannelArchived, $channel, [
            'channel_name' => $channel->name,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Archived #:channel.', ['channel' => $channel->name])]);

        return to_route('channels.index', ['team' => $team->slug]);
    }
}
