<?php

namespace App\Http\Controllers\Teams;

use App\Actions\Teams\CreateTeam;
use App\Enums\AuditAction;
use App\Enums\SecurityEventType;
use App\Enums\TeamPermission;
use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\DeleteTeamRequest;
use App\Http\Requests\Teams\SaveTeamRequest;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use App\Support\AuditRecorder;
use App\Support\SecurityEventRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Team created.')]);

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

        return Inertia::render('teams/Edit', [
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'slug' => $team->slug,
                'isPersonal' => $team->is_personal,
                'role' => $user->teamRole($team)?->value,
            ],
            'members' => $team->members()->get()->map(function (User $member) use ($canViewRoster): array {
                /** @var Membership $membership */
                $membership = $member->getRelation('pivot');

                return [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $canViewRoster ? $member->email : null,
                    'avatar' => $member->avatar ?? null,
                    'role' => $membership->role->value,
                    'role_label' => $membership->role->label(),
                ];
            }),
            'invitations' => $canViewRoster
                ? $team->invitations()
                    ->whereNull('accepted_at')
                    ->get()
                    ->map(fn ($invitation): array => [
                        'code' => $invitation->code,
                        'email' => $invitation->email,
                        'role' => $invitation->role->value,
                        'role_label' => $invitation->role->label(),
                        'created_at' => $invitation->created_at->toISOString(),
                    ])
                : collect(),
            'permissions' => $user->toTeamPermissions($team),
            'availableRoles' => TeamRole::assignable(),
        ]);
    }

    /**
     * Update the specified team.
     */
    public function update(SaveTeamRequest $request, Team $team, AuditRecorder $recorder): RedirectResponse
    {
        Gate::authorize('update', $team);

        $oldName = $team->name;
        $newName = $request->validated('name');

        $team = DB::transaction(function () use ($newName, $team) {
            $team = Team::whereKey($team->id)->lockForUpdate()->firstOrFail();

            $team->update(['name' => $newName]);

            return $team;
        });

        if ($newName !== $oldName) {
            $recorder->record($team, $request->user(), AuditAction::TeamRenamed, $team, [
                'old_name' => $oldName,
                'new_name' => $newName,
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Team updated.')]);

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

        if ($fallbackTeam) {
            $user->switchTeam($fallbackTeam);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('You left the team ":name"', ['name' => $team->name])]);

        return to_route('teams.index');
    }

    /**
     * Delete the specified team.
     */
    public function destroy(DeleteTeamRequest $request, Team $team, SecurityEventRecorder $securityEvents): RedirectResponse
    {
        $user = $request->user();
        $fallbackTeam = $user->isCurrentTeam($team) ? $user->fallbackTeam($team) : null;

        DB::transaction(function () use ($user, $team): void {
            User::where('current_team_id', $team->id)
                ->where('id', '!=', $user->id)
                ->each(fn (User $affectedUser): bool => $affectedUser->switchTeam($affectedUser->personalTeam()));

            $team->invitations()->delete();
            $team->memberships()->delete();
            $team->delete();
        });

        $securityEvents->record($user, SecurityEventType::TeamDeleted);

        if ($fallbackTeam) {
            $user->switchTeam($fallbackTeam);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Team deleted.')]);

        return to_route('teams.index');
    }
}
