<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;

class TeamPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Team $team): bool
    {
        return $user->belongsToTeam($team);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::UpdateTeam);
    }

    /**
     * Determine whether the user can leave the team.
     */
    public function leave(User $user, Team $team): bool
    {
        return ! $team->is_personal
            && $user->belongsToTeam($team)
            && ! $user->ownsTeam($team);
    }

    /**
     * Determine whether the user can add a member to the team.
     */
    public function addMember(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::AddMember);
    }

    /**
     * Determine whether the user can update a member's role in the team.
     */
    public function updateMember(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::UpdateMember);
    }

    /**
     * Determine whether the user can remove a member from the team.
     */
    public function removeMember(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::RemoveMember);
    }

    /**
     * Determine whether the user can invite members to the team.
     */
    public function inviteMember(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::CreateInvitation);
    }

    /**
     * Determine whether the user can cancel invitations.
     */
    public function cancelInvitation(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::CancelInvitation);
    }

    /**
     * Determine whether the user can transfer ownership of the team.
     *
     * Ownership is strictly the sole owner's to give away, so this is not a
     * delegable {@see TeamPermission}: only the current owner may initiate it.
     */
    public function transferOwnership(User $user, Team $team): bool
    {
        return ! $team->is_personal && $user->ownsTeam($team);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Team $team): bool
    {
        return ! $team->is_personal && $user->hasTeamPermission($team, TeamPermission::DeleteTeam);
    }

    /**
     * Determine whether the user can view the team's audit log.
     *
     * The log surfaces moderation and admin actions, so it is scoped to admins
     * and the owner of a real (non-personal) workspace.
     */
    public function viewAudit(User $user, Team $team): bool
    {
        return ! $team->is_personal
            && ($user->teamRole($team)?->isAtLeast(TeamRole::Admin) ?? false);
    }

    /**
     * Determine whether the user can view the team's security-event log.
     *
     * The log surfaces account-level security events for the workspace's current
     * members, so it is scoped to admins and the owner of a real (non-personal)
     * workspace, mirroring {@see self::viewAudit()}.
     */
    public function viewSecurityLog(User $user, Team $team): bool
    {
        return ! $team->is_personal
            && ($user->teamRole($team)?->isAtLeast(TeamRole::Admin) ?? false);
    }

    /**
     * Determine whether the user can view the team's analytics dashboard.
     *
     * The dashboard aggregates workspace-wide activity, so it is scoped to
     * admins and the owner of a real (non-personal) workspace.
     */
    public function viewAnalytics(User $user, Team $team): bool
    {
        return ! $team->is_personal
            && ($user->teamRole($team)?->isAtLeast(TeamRole::Admin) ?? false);
    }

    /**
     * Determine whether the user can manage the team's integrations.
     *
     * The integrations surface mints bot credentials and webhook secrets, so it
     * is scoped to the {@see TeamPermission::ManageIntegrations} holders (Owner +
     * Admin) of a real (non-personal) workspace.
     */
    public function manageIntegrations(User $user, Team $team): bool
    {
        return ! $team->is_personal
            && $user->hasTeamPermission($team, TeamPermission::ManageIntegrations);
    }
}
