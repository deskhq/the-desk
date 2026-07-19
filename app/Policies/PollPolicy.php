<?php

namespace App\Policies;

use App\Enums\TeamRole;
use App\Models\Poll;
use App\Models\User;

class PollPolicy
{
    /**
     * Determine whether the user can close the poll.
     *
     * The poll's creator (the message author) may close their own poll, and a
     * team Admin+ may close any poll in the team as a moderation action — the
     * same creator-or-admin rule that governs deleting the poll message.
     */
    public function close(User $user, Poll $poll): bool
    {
        return $poll->message->user_id === $user->id
            || ($user->teamRole($poll->message->channel->team)?->isAtLeast(TeamRole::Admin) ?? false);
    }
}
