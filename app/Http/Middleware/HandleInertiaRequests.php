<?php

namespace App\Http\Middleware;

use App\Data\ChannelData;
use App\Data\ChannelSectionData;
use App\Enums\TeamRole;
use App\Models\Message;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;
use Laravel\Fortify\Features;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            // A single deploy-time flag lets self-hosters lock down public
            // registration; when off, Fortify never registers the register
            // routes, so the frontend hides its "sign up" affordances to match.
            'registrationEnabled' => Features::enabled(Features::registration()),
            'auth' => [
                'user' => $user,
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'currentTeam' => fn () => $user?->currentTeam ? $user->toUserTeam($user->currentTeam) : null,
            'teams' => fn () => $user?->toUserTeams(includeCurrent: true) ?? [],
            // The dock header's "invite" affordance reuses the member-invite modal,
            // so the current team's invite permission and the assignable roles ride
            // along with every workspace request.
            'canInviteToCurrentTeam' => fn () => $user?->currentTeam
                ? $user->toTeamPermissions($user->currentTeam)->canCreateInvitation
                : false,
            'invitableRoles' => TeamRole::assignable(),
            'channels' => fn () => $this->channelsForSidebar($request, $user),
            'channelSections' => fn () => $this->channelSectionsForSidebar($request, $user),
            'collapsedChannelSections' => fn () => $user->collapsed_channel_sections ?? [],
            'hasUnreadThreads' => fn () => $this->hasUnreadThreads($request, $user),
            'pendingInvitations' => Inertia::optional(fn () => $user ? $this->pendingInvitationsFor($user) : []),
        ];
    }

    /**
     * The current user's channels for the workspace sidebar, scoped to the team in the URL.
     *
     * @return array<int, ChannelData>
     */
    protected function channelsForSidebar(Request $request, ?User $user): array
    {
        $team = $request->route('team');

        if (! $user || ! $team instanceof Team || ! $request->routeIs('channels.*')) {
            return [];
        }

        $channels = $user->channels()
            ->where('channels.team_id', $team->id)
            ->whereNull('channels.archived_at')
            ->select('channels.*')
            ->addSelect(['channel_members.muted', 'channel_members.notification_level', 'channel_members.starred', 'channel_members.section_id', 'channel_members.position'])
            // Only the presence of a draft drives the sidebar cue; the draft text
            // itself is shipped solely to the open channel, so keep it out of the
            // sidebar payload and expose a 1/0 flag instead (an integer, not a
            // driver-specific boolean, so the DTO's cast reads it reliably).
            ->selectRaw("case when channel_members.draft is not null and channel_members.draft != '' then 1 else 0 end as has_draft")
            // Thread-only replies stay out of the plain unread badge (they live
            // in the thread view), but a mention anywhere — including inside a
            // thread — still badges the channel.
            ->selectSub($this->unreadMessages($user)
                ->where(fn (Builder $query) => $query->whereNull('messages.thread_root_id')->orWhere('messages.sent_to_channel', true)), 'unread_count')
            ->selectSub($this->unreadMessages($user)->whereHas('mentionedUsers', fn ($query) => $query->whereKey($user->id)), 'mention_count')
            // Manual order within each sidebar group first, then alphabetical as a
            // stable tiebreak for channels the user has never reordered.
            ->orderBy('channel_members.position')
            ->orderBy('channels.name')
            ->get();

        return ChannelData::collect($channels, 'array');
    }

    /**
     * The current user's custom sidebar sections for the team in the URL.
     *
     * Ordered by the user's manual section order, so the sidebar can render the
     * custom groups between "Starred" and the default "Channels" list. Empty off
     * the channel workspace, where the sidebar is absent.
     *
     * @return array<int, ChannelSectionData>
     */
    protected function channelSectionsForSidebar(Request $request, ?User $user): array
    {
        $team = $request->route('team');

        if (! $user || ! $team instanceof Team || ! $request->routeIs('channels.*')) {
            return [];
        }

        $sections = $user->channelSections()
            ->where('team_id', $team->id)
            ->orderBy('position')
            ->orderBy('created_at')
            ->get();

        return ChannelSectionData::collect($sections, 'array');
    }

    /**
     * Whether the user has any unread followed thread in the team, driving the
     * sidebar's "Threads" unread dot.
     *
     * Scoped to the user's channels in the team (the same ACL as the inbox), and
     * muted per channel, so it agrees with the dots the inbox and channel views
     * show. Returns false off the channel workspace, where the sidebar is absent.
     */
    protected function hasUnreadThreads(Request $request, ?User $user): bool
    {
        $team = $request->route('team');

        if (! $user || ! $team instanceof Team || ! $request->routeIs('channels.*')) {
            return false;
        }

        return Message::query()
            ->whereIn('channel_id', $user->visibleChannelIds($team))
            ->whereNull('thread_root_id')
            ->where('reply_count', '>', 0)
            ->followedBy($user)
            ->whereThreadUnreadFor($user)
            ->exists();
    }

    /**
     * A correlated sub-query counting a channel's messages the user has not yet read.
     *
     * "Unread" is every non-deleted message authored by someone else that lands
     * after the user's `last_read_message_id` (a null pointer means the channel
     * was never opened, so everything counts). It is correlated against the outer
     * sidebar query's `channels` and `channel_members` rows, so a single query
     * fills every channel's badge without an N+1.
     *
     * @return Builder<Message>
     */
    protected function unreadMessages(User $user): Builder
    {
        return Message::query()
            ->selectRaw('count(*)')
            ->whereColumn('messages.channel_id', 'channels.id')
            ->where('messages.user_id', '!=', $user->id)
            ->where(fn (Builder $query) => $query
                ->whereNull('channel_members.last_read_message_id')
                ->orWhereColumn('messages.id', '>', 'channel_members.last_read_message_id'));
    }

    /**
     * The user's pending (unaccepted, unexpired) team invitations.
     *
     * @return array<int, array{code: string, inviterName: string, team: array{name: string, slug: string}}>
     */
    protected function pendingInvitationsFor(User $user): array
    {
        $email = strtolower($user->email);

        return TeamInvitation::query()
            ->with(['inviter', 'team'])
            ->whereRaw('LOWER(email) = ?', [$email])
            ->whereNull('accepted_at')
            ->where(fn ($query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>=', now()))
            ->latest()
            ->get()
            ->map(fn (TeamInvitation $invitation) => [
                'code' => $invitation->code,
                'inviterName' => $invitation->inviter->name,
                'team' => [
                    'name' => $invitation->team->name,
                    'slug' => $invitation->team->slug,
                ],
            ])
            ->all();
    }
}
