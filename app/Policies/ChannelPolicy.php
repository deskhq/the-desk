<?php

namespace App\Policies;

use App\Enums\ChannelVisibility;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\User;

class ChannelPolicy
{
    /**
     * Determine whether the user can view the channel.
     */
    public function view(User $user, Channel $channel): bool
    {
        if (! $user->belongsToTeam($channel->team)) {
            return false;
        }

        return $channel->visibility === ChannelVisibility::Public
            || $channel->members()->whereKey($user->id)->exists();
    }

    /**
     * Determine whether the user can archive the channel.
     *
     * The #general channel can never be archived. Otherwise the channel's
     * creator or a team Admin+ may archive a non-archived channel.
     */
    public function archive(User $user, Channel $channel): bool
    {
        if ($channel->isGeneral() || $channel->isArchived()) {
            return false;
        }

        if (! $user->belongsToTeam($channel->team)) {
            return false;
        }

        return $channel->created_by === $user->id
            || ($user->teamRole($channel->team)?->isAtLeast(TeamRole::Admin) ?? false);
    }

    /**
     * Determine whether the user can delete the channel.
     *
     * The #general channel can never be deleted; hard-delete of other channels
     * is reserved for team Admin+ (no hard-delete UI in the MVP).
     */
    public function delete(User $user, Channel $channel): bool
    {
        if ($channel->isGeneral()) {
            return false;
        }

        return $user->belongsToTeam($channel->team)
            && ($user->teamRole($channel->team)?->isAtLeast(TeamRole::Admin) ?? false);
    }
}
