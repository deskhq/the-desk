<?php

namespace App\Http\Controllers\Channels;

use App\Actions\Channels\ArchiveChannel;
use App\Actions\Channels\CreateChannel;
use App\Actions\Channels\DeleteChannel;
use App\Actions\Channels\JoinChannel;
use App\Actions\Channels\LeaveChannel;
use App\Actions\Channels\MarkChannelRead;
use App\Actions\Channels\MarkThreadRead;
use App\Actions\Channels\UpdateChannel;
use App\Data\ChannelData;
use App\Data\UserData;
use App\Enums\ChannelVisibility;
use App\Enums\NotificationLevel;
use App\Events\UserTyping;
use App\Http\Controllers\Controller;
use App\Http\Requests\Channels\CreateChannelRequest;
use App\Http\Requests\Channels\DeleteChannelRequest;
use App\Http\Requests\Channels\UpdateChannelRequest;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Team;
use App\Support\ChannelPage;
use App\Support\ChannelTimelineWindow;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ChannelController extends Controller
{
    /**
     * Redirect a bare team URL to the team's #general channel.
     *
     * The query string rides along so state pinned on the shell route — the
     * dock's `?nav=` destination, a shared search's filters — survives the hop
     * to the channel that actually renders it. The route parameters are spread
     * last, so a crafted `?team=`/`?channel=` cannot redirect the hop elsewhere.
     */
    public function index(Request $request, Team $team): RedirectResponse
    {
        return to_route('channels.show', [
            ...$request->query(),
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

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Channel created')]);

        return to_route('channels.show', ['team' => $team->slug, 'channel' => $channel->slug]);
    }

    /**
     * Update the channel's own details — its name, topic, and description.
     *
     * The request authorizes the member-vs-creator/Admin split (any member may
     * edit the topic and description; only a creator or team Admin+ may rename),
     * and only validated keys are applied, so a partial edit leaves the rest
     * alone. No audit case: these are routine collaborative edits, and a name or
     * topic change already leaves its own system notice in the timeline.
     */
    public function update(UpdateChannelRequest $request, Team $team, Channel $channel, UpdateChannel $updateChannel): RedirectResponse
    {
        $updateChannel->handle($channel, $request->user(), $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Channel updated')]);

        return back();
    }

    /**
     * Show a channel. The channel sidebar is fed by the globally-shared `channels` prop.
     *
     * Two read-models, held side by side. {@see ChannelPage} owns the payload —
     * the channel itself, the viewer's standing in it, the controls, the pins,
     * the roster, the receipts, the schedules — and {@see ChannelTimelineWindow}
     * owns where the initial message window opens. They are separate because
     * only the second one answers to the URL: it takes the raw `?message=` and
     * `?thread=` values, and a read-model that knows about query strings is what
     * ADR-0008 keeps out. So this action stays HTTP glue: authorize, resolve the
     * two params, name the props.
     */
    public function show(Request $request, Team $team, Channel $channel): Response
    {
        Gate::authorize('view', $channel);

        $page = new ChannelPage($channel, $request->user());

        $window = new ChannelTimelineWindow(
            channel: $channel,
            viewer: $request->user(),
            requestedJumpId: $request->query('message'),
            lastReadMessageId: $page->lastReadMessageId(),
            requestedThreadRootId: $request->query('thread'),
        );

        return Inertia::render('channels/Show', [
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'slug' => $team->slug,
            ],
            'channel' => $page->channel(),
            // The seven `can*` props gating the masthead's controls, spread from
            // the one reading that asks the policy for all of them.
            ...$page->capabilities(),
            // Whether the viewer already belongs to the channel, and how many
            // teammates do — the join call-to-action a non-member sees instead of
            // the composer reads both.
            'isMember' => $page->isMember(),
            'memberCount' => $page->memberCount(),
            // The pins popover's rows and the masthead badge's count, which are
            // one reading so they cannot disagree; later pins/unpins patch both
            // live over MessagePinned.
            ...$page->pins(),
            // Selectable notification levels for the settings menu.
            'notificationLevels' => NotificationLevel::options(),
            // The message the client should scroll to and highlight on load, or
            // null for a normal channel visit.
            'jumpToMessageId' => $window->jumpToMessageId(),
            // The viewer's read pointer captured at render time, before the
            // client's debounced MarkChannelRead advances it. Drives the
            // "New messages" divider so it lands at the last-read boundary on
            // open; null when the channel has never been read.
            'lastReadMessageId' => $page->lastReadMessageId(),
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
            // The viewer's own pending scheduled messages, feeding the composer's
            // "Scheduled" affordance.
            'scheduledMessages' => $page->scheduledMessages(),
            // The one part of the channel's roster no other prop in this response
            // carries. The masthead facepile and the composer's @mention
            // autocomplete read the whole roster, which the client composes from
            // the shell's `teamMembers` (or, for a DM, `channel.dmParticipants`)
            // and these.
            'botMembers' => $page->botMembers(),
            // Read pointers of the channel's other members who share read receipts,
            // seeding the "Seen by" affordance at open; later advances arrive via the
            // MessageRead broadcast.
            'channelReaders' => $page->readers(),
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
            'joinableChannels' => $channels->map(fn (Channel $channel): ChannelData => ChannelData::fromChannel($channel, $request->user()))->all(),
        ]);
    }

    /**
     * Join a public channel and redirect to it.
     */
    public function join(Request $request, Team $team, Channel $channel, JoinChannel $joinChannel): RedirectResponse
    {
        Gate::authorize('join', $channel);

        $joinChannel->handle($channel, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Joined #:channel', ['channel' => $channel->name])]);

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
     * Broadcast that the current user is composing a message in the channel.
     *
     * The typist identity is taken from the authenticated user — never from the
     * request body — so a channel member cannot spoof another member's typing
     * indicator (the reason this replaced the client-to-client whisper). The
     * broadcast goes to others only; the typist never sees their own indicator.
     */
    public function typing(Request $request, Team $team, Channel $channel): HttpResponse
    {
        Gate::authorize('postMessage', $channel);

        broadcast(new UserTyping($channel, UserData::fromUser($request->user())))->toOthers();

        return response()->noContent();
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

        $archiveChannel->handle($channel, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Archived #:channel', ['channel' => $channel->name])]);

        return to_route('channels.index', ['team' => $team->slug]);
    }

    /**
     * Delete a channel and redirect to the team's #general channel.
     *
     * The request authorizes the Admin+ gate (which also rejects #general and
     * direct messages) and re-checks the typed channel name, so reaching here is
     * a deliberate, authorized destruction. The channel disappears from every
     * surface at once, so #general — which always exists — is where the admin
     * lands.
     */
    public function destroy(DeleteChannelRequest $request, Team $team, Channel $channel, DeleteChannel $deleteChannel): RedirectResponse
    {
        $channel = $deleteChannel->handle($channel, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Deleted #:channel', ['channel' => $channel->name])]);

        return to_route('channels.show', ['team' => $team->slug, 'channel' => Channel::GENERAL_SLUG]);
    }
}
