<?php

namespace App\Http\Controllers\Teams;

use App\Data\UserProfileData;
use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\UpdateTeamMemberRequest;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TeamMemberController extends Controller
{
    /**
     * Show the profile page for a member of the team.
     *
     * Scoped to the team the viewer already belongs to (enforced by the route's
     * membership middleware); a user who is not a member of that team resolves
     * to a 404 so profiles never leak across team boundaries.
     */
    public function show(Request $request, Team $team, User $user): Response
    {
        /** @var Membership|null $membership */
        $membership = $team->memberships()
            ->where('user_id', $user->id)
            ->first();

        abort_if($membership === null, 404);

        return Inertia::render('teams/MemberProfile', [
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'slug' => $team->slug,
            ],
            'profile' => UserProfileData::forMember($user, $membership, $request->user()),
        ]);
    }

    /**
     * Update the specified team member's role.
     */
    public function update(UpdateTeamMemberRequest $request, Team $team, User $user): RedirectResponse
    {
        Gate::authorize('updateMember', $team);

        $newRole = TeamRole::from($request->validated('role'));

        $team->memberships()
            ->where('user_id', $user->id)
            ->firstOrFail()
            ->update(['role' => $newRole]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member role updated.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    /**
     * Remove the specified team member.
     */
    public function destroy(Team $team, User $user): RedirectResponse
    {
        Gate::authorize('removeMember', $team);

        abort_if($team->owner()?->is($user), 403, __('The team owner cannot be removed.'));

        $team->memberships()
            ->where('user_id', $user->id)
            ->delete();

        if ($user->isCurrentTeam($team)) {
            $user->switchTeam($user->personalTeam());
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member removed.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }
}
