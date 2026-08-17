<?php

declare(strict_types=1);

namespace App\Http\Controllers\Teams;

use App\Actions\Teams\RemoveTeamMember;
use App\Actions\Teams\TransferTeamOwnership;
use App\Actions\Teams\UpdateTeamMemberRole;
use App\Data\UserProfileData;
use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\TransferTeamOwnershipRequest;
use App\Http\Requests\Teams\UpdateTeamMemberRequest;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class TeamMemberController extends Controller
{
    /**
     * Show the profile page for a member of the team.
     *
     * Scoped to the team the viewer already belongs to (enforced by the route's
     * membership middleware); a user who is not a member of that team resolves
     * to a 404 so profiles never leak across team boundaries.
     */
    public function show(Request $request, Team $team, User $member): Response
    {
        return Inertia::render('teams/MemberProfile', [
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'slug' => $team->slug,
            ],
            'profile' => UserProfileData::forMember($member, $this->membership($member), $this->viewer($request)),
        ]);
    }

    /**
     * Return a member's profile as JSON for the on-hover profile card.
     *
     * Same team-scoped visibility as {@see show()}; fetched lazily by the hover
     * card so names across the app can reveal richer details on demand.
     */
    public function card(Request $request, Team $team, User $member): UserProfileData
    {
        return UserProfileData::forMember($member, $this->membership($member), $this->viewer($request));
    }

    /**
     * The membership row the route already resolved the member through.
     *
     * `{member}` is scoped to `Team::members()`, so the pivot arrives hydrated
     * and a non-member is a 404 before this controller runs — there is nothing
     * left here to re-check.
     */
    private function membership(User $member): Membership
    {
        /** @var Membership $membership */
        $membership = $member->getRelation('pivot');

        return $membership;
    }

    /**
     * Update the specified team member's role.
     */
    public function update(UpdateTeamMemberRequest $request, Team $team, User $member, UpdateTeamMemberRole $updateRole): RedirectResponse
    {
        Gate::authorize('updateMember', $team);

        $updateRole->handle($team, $member, TeamRole::from($request->validated('role')), $this->viewer($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member role updated')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    /**
     * Transfer ownership of the team to the specified member.
     *
     * Only the current owner may initiate this, and the target must already be a
     * member of the team. The outgoing owner is demoted to Admin and the new
     * owner holds the sole Owner pivot row (see {@see TransferTeamOwnership}).
     */
    public function transferOwnership(TransferTeamOwnershipRequest $request, Team $team, User $member, TransferTeamOwnership $transferOwnership): RedirectResponse
    {
        Gate::authorize('transferOwnership', $team);

        abort_if($this->viewer($request)->is($member), 403, __('You cannot transfer ownership to yourself.'));

        $transferOwnership->handle($team, $this->viewer($request), $member);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Team ownership transferred')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    /**
     * Remove the specified team member.
     */
    public function destroy(Request $request, Team $team, User $member, RemoveTeamMember $removeMember): RedirectResponse
    {
        Gate::authorize('removeMember', $team);

        abort_if($team->owner()?->is($member) === true, 403, __('The team owner cannot be removed.'));

        $removeMember->handle($team, $member, $this->viewer($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member removed')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }
}
