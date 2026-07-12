<?php

namespace App\Policies;

use App\Enums\TeamRole;
use App\Models\CustomEmoji;
use App\Models\Team;
use App\Models\User;

class CustomEmojiPolicy
{
    /**
     * Determine whether the user can add a custom emoji to the workspace.
     *
     * The registry is open: any member may upload and name an emoji.
     */
    public function create(User $user, Team $team): bool
    {
        return $user->belongsToTeam($team);
    }

    /**
     * Determine whether the user can remove a custom emoji.
     *
     * A member may delete their own upload; an admin (or owner) of a real
     * workspace may revoke anyone's. The single ability backs both the
     * self-service "Delete" and the admin "Revoke" affordances.
     */
    public function delete(User $user, CustomEmoji $emoji): bool
    {
        if ($emoji->created_by === $user->id) {
            return true;
        }

        $team = $emoji->team;

        return ! $team->is_personal
            && ($user->teamRole($team)?->isAtLeast(TeamRole::Admin) ?? false);
    }
}
