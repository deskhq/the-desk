<?php

namespace App\Http\Controllers\Channels;

use App\Actions\Channels\ArchiveChannel;
use App\Actions\Channels\CreateChannel;
use App\Actions\Channels\JoinChannel;
use App\Actions\Channels\MarkChannelRead;
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
            // Team members feed the composer's @mention autocomplete; mentions are
            // scoped to the team, never limited to the current channel's members.
            'members' => UserData::collect($team->members()->orderBy('name')->get()),
            // Newest 50 first; the InfiniteScroll composer runs in reverse mode, so
            // scrolling up appends older pages and the client reverses for display.
            // Deleted rows are kept (withTrashed) so the client can render a
            // "message deleted" tombstone in place; MessageData blanks their body.
            'messages' => Inertia::scroll(fn () => $channel->messages()
                ->withTrashed()
                ->with(['user', 'mentionedUsers'])
                ->orderByDesc('id')
                ->cursorPaginate(50)
                ->through(fn (Message $message) => MessageData::fromMessage($message))),
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
