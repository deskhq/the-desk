<?php

namespace App\Policies;

use App\Enums\TeamRole;
use App\Models\Message;
use App\Models\User;

class MessagePolicy
{
    /**
     * Determine whether the user can edit the message.
     *
     * Only the author may edit their own message; edits are never a moderation
     * action. System notices are inert and carry no editable body, so no one may
     * edit them even though the actor is recorded as their author.
     */
    public function update(User $user, Message $message): bool
    {
        if ($message->isSystem()) {
            return false;
        }

        return $message->user_id === $user->id;
    }

    /**
     * Determine whether the user can delete the message.
     *
     * The author may delete their own message, and a team Admin+ may delete any
     * message in the team as a moderation action. System notices are inert, so
     * neither the recorded actor nor a moderator may delete them.
     */
    public function delete(User $user, Message $message): bool
    {
        if ($message->isSystem()) {
            return false;
        }

        return $message->user_id === $user->id
            || ($user->teamRole($message->channel->team)?->isAtLeast(TeamRole::Admin) ?? false);
    }
}
