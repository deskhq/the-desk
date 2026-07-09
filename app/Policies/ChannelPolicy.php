<?php

namespace App\Policies;

use App\Enums\ChannelVisibility;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\Team;
use App\Models\User;

class ChannelPolicy
{
    /**
     * Determine whether the user can create a channel in the team.
     *
     * Any team member (Member+) may create a channel.
     */
    public function create(User $user, Team $team): bool
    {
        return $user->belongsToTeam($team);
    }

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
     * Determine whether the user can update their own notification preferences.
     *
     * Preferences live on the membership pivot, so only a member of the channel
     * (within the team) has any to manage. Each member only ever touches their
     * own row.
     */
    public function updatePreference(User $user, Channel $channel): bool
    {
        return $user->belongsToTeam($channel->team)
            && $channel->members()->whereKey($user->id)->exists();
    }

    /**
     * Determine whether the user can post a message to the channel.
     *
     * Only members of a non-archived channel may post.
     */
    public function postMessage(User $user, Channel $channel): bool
    {
        if ($channel->isArchived()) {
            return false;
        }

        return $channel->members()->whereKey($user->id)->exists();
    }

    /**
     * Determine whether the user can join the channel by browsing.
     *
     * Only non-archived public channels are self-joinable, and only by team
     * members. Private channels are invite-only (see addMember).
     */
    public function join(User $user, Channel $channel): bool
    {
        return $user->belongsToTeam($channel->team)
            && $channel->visibility === ChannelVisibility::Public
            && ! $channel->isArchived();
    }

    /**
     * Determine whether the user can add members to the channel.
     *
     * Private channel membership is managed by existing channel members or by
     * a team Admin+. Public channels are self-service (see join).
     */
    public function addMember(User $user, Channel $channel): bool
    {
        return $this->managesMembership($user, $channel);
    }

    /**
     * Determine whether the user can remove members from the channel.
     */
    public function removeMember(User $user, Channel $channel): bool
    {
        return $this->managesMembership($user, $channel);
    }

    /**
     * Shared rule for managing a private channel's membership.
     */
    private function managesMembership(User $user, Channel $channel): bool
    {
        if ($channel->visibility !== ChannelVisibility::Private) {
            return false;
        }

        if (! $user->belongsToTeam($channel->team)) {
            return false;
        }

        return $channel->members()->whereKey($user->id)->exists()
            || ($user->teamRole($channel->team)?->isAtLeast(TeamRole::Admin) ?? false);
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
