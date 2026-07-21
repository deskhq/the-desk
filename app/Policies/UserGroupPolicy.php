<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Models\Team;
use App\Models\User;
use App\Models\UserGroup;

/**
 * Every member of a workspace may *mention* a group; only admins and owners
 * curate them. The single `user-group:manage` permission backs create, rename,
 * delete, and membership edits alike — there is no finer-grained split.
 */
class UserGroupPolicy
{
    /**
     * Determine whether the user can see the workspace's groups registry.
     *
     * Reading the list is management-only; the mentionable roster every member
     * needs rides along on the workspace's shared Inertia props instead.
     */
    public function viewAny(User $user, Team $team): bool
    {
        return $this->manages($user, $team);
    }

    /**
     * Determine whether the user can create a group in the workspace.
     */
    public function create(User $user, Team $team): bool
    {
        return $this->manages($user, $team);
    }

    /**
     * Determine whether the user can rename a group or edit its membership.
     */
    public function update(User $user, UserGroup $group): bool
    {
        return $this->manages($user, $group->team);
    }

    /**
     * Determine whether the user can delete a group.
     */
    public function delete(User $user, UserGroup $group): bool
    {
        return $this->manages($user, $group->team);
    }

    /**
     * Whether the user curates groups in this workspace. Personal teams are
     * excluded: a one-person workspace has nothing to group.
     */
    private function manages(User $user, Team $team): bool
    {
        return ! $team->is_personal
            && $user->hasTeamPermission($team, TeamPermission::ManageUserGroups);
    }
}
