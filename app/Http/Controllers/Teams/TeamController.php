<?php

namespace App\Http\Controllers\Teams;

use App\Actions\Teams\CreateTeam;
use App\Actions\Teams\DeleteTeam;
use App\Actions\Teams\UpdateTeam;
use App\Data\UserStatusData;
use App\Enums\ChannelCreationPolicy;
use App\Enums\ChannelVisibility;
use App\Enums\TeamPermission;
use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\DeleteTeamRequest;
use App\Http\Requests\Teams\SaveTeamRequest;
use App\Models\Channel;
use App\Models\Membership;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends Controller
{
    /**
     * Display a listing of the user's teams.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('teams/Index', [
            'teams' => $user->toUserTeams(includeCurrent: true),
        ]);
    }

    /**
     * Store a newly created team.
     */
    public function store(SaveTeamRequest $request, CreateTeam $createTeam): RedirectResponse
    {
        $team = $createTeam->handle($request->user(), $request->validated('name'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Team created')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    /**
     * Show the team edit page.
     *
     * Member emails and the pending-invitation list are sensitive roster data,
     * so they are only included for users who manage invitations (Owner and
     * Admin via {@see TeamPermission::CreateInvitation}); plain Members get a
     * null email per member and an empty invitation list.
     */
    public function edit(Request $request, Team $team): Response
    {
        Gate::authorize('view', $team);

        $user = $request->user();
        $canViewRoster = Gate::allows('inviteMember', $team);
        $canAdminister = Gate::allows('update', $team);

        return Inertia::render('teams/Edit', [
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'slug' => $team->slug,
                'isPersonal' => $team->is_personal,
                'role' => $user->teamRole($team)?->value,
            ],
            'members' => $team->roster()->get()->map(function (User $member) use ($canViewRoster): array {
                /** @var Membership $membership */
                $membership = $member->getRelation('pivot');

                return [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $canViewRoster ? $member->email : null,
                    'avatar' => $member->avatar ?? null,
                    'role' => $membership->role->value,
                    'role_label' => $membership->role->label(),
                    'status' => UserStatusData::forUser($member),
                ];
            }),
            'invitations' => $canViewRoster
                ? $team->invitations()
                    ->whereNull('accepted_at')
                    ->get()
                    ->map(fn (TeamInvitation $invitation): array => [
                        'code' => $invitation->code,
                        'email' => $invitation->email,
                        'role' => $invitation->role->value,
                        'role_label' => $invitation->role->label(),
                        'created_at' => $invitation->created_at->toISOString(),
                    ])
                : collect(),
            'permissions' => $user->toTeamPermissions($team),
            'availableRoles' => TeamRole::assignable(),
            'channelCreation' => [
                'public' => $team->public_channel_creation_policy->value,
                'private' => $team->private_channel_creation_policy->value,
                'options' => ChannelCreationPolicy::options(),
            ],
            'defaultChannels' => $canAdminister
                ? $this->defaultChannelCandidates($team)
                : collect(),
        ]);
    }

    /**
     * List the channels an admin may mark as workspace defaults.
     *
     * Only a live public channel can be one — a private channel cannot be joined
     * unasked and an archived one is read-only — so the rest are simply absent
     * rather than shown disabled. The protected #general leads the list and is
     * flagged so the UI can render it as the permanent default it is.
     *
     * @return Collection<int, array{slug: string, name: string, isDefault: bool, isGeneral: bool}>
     */
    private function defaultChannelCandidates(Team $team): Collection
    {
        return $team->channels()
            ->where('visibility', ChannelVisibility::Public->value)
            ->whereNull('archived_at')
            ->orderByRaw('case when slug = ? then 0 else 1 end', [Channel::GENERAL_SLUG])
            ->orderByRaw('lower(name)')
            ->get()
            ->map(fn (Channel $channel): array => [
                'slug' => $channel->slug,
                'name' => (string) $channel->name,
                'isDefault' => $channel->is_default,
                'isGeneral' => $channel->isGeneral(),
            ]);
    }

    /**
     * Update the specified team.
     */
    public function update(SaveTeamRequest $request, Team $team, UpdateTeam $updateTeam): RedirectResponse
    {
        Gate::authorize('update', $team);

        $team = $updateTeam->handle($team, $request->user(), $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Team updated')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    /**
     * Switch the user's current team.
     */
    public function switch(Request $request, Team $team): RedirectResponse
    {
        abort_unless($request->user()->belongsToTeam($team), 403);

        $request->user()->switchTeam($team);

        return back();
    }

    /**
     * Leave the specified team.
     */
    public function leave(Request $request, Team $team): RedirectResponse
    {
        Gate::authorize('leave', $team);

        $user = $request->user();

        $fallbackTeam = $user->isCurrentTeam($team) ? $user->fallbackTeam($team) : null;

        $team->memberships()
            ->where('user_id', $user->id)
            ->delete();

        $user->leaveUserGroups($team);

        if ($fallbackTeam) {
            $user->switchTeam($fallbackTeam);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('You left the team ":name"', ['name' => $team->name])]);

        return to_route('teams.index');
    }

    /**
     * Delete the specified team.
     */
    public function destroy(DeleteTeamRequest $request, Team $team, DeleteTeam $deleteTeam): RedirectResponse
    {
        $user = $request->user();
        $fallbackTeam = $user->isCurrentTeam($team) ? $user->fallbackTeam($team) : null;

        $deleteTeam->handle($team, $user);

        if ($fallbackTeam) {
            $user->switchTeam($fallbackTeam);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Team deleted')]);

        return to_route('teams.index');
    }
}
