<?php

namespace App\Http\Middleware;

use App\Data\ChannelData;
use App\Models\Message;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;

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
            'auth' => [
                'user' => $user,
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'currentTeam' => fn () => $user?->currentTeam ? $user->toUserTeam($user->currentTeam) : null,
            'teams' => fn () => $user?->toUserTeams(includeCurrent: true) ?? [],
            'channels' => fn () => $this->channelsForSidebar($request, $user),
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
            ->addSelect(['channel_members.muted', 'channel_members.notification_level'])
            ->selectSub($this->unreadMessages($user), 'unread_count')
            ->selectSub($this->unreadMessages($user)->whereHas('mentionedUsers', fn ($query) => $query->whereKey($user->id)), 'mention_count')
            ->orderBy('name')
            ->get();

        return ChannelData::collect($channels, 'array');
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
