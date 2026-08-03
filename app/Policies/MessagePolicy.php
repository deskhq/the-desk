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
     * A message may only be mutated in a channel the actor can currently
     * participate in: the author must still be a channel member and the channel
     * must not be archived (the same rule as posting). Edits are never a
     * moderation action, so there is no admin branch. System notices are inert
     * and carry no editable body, so no one may edit them even though the actor
     * is recorded as their author.
     */
    public function update(User $user, Message $message): bool
    {
        if ($message->isSystem()) {
            return false;
        }

        return $message->user_id === $user->id
            && $user->can('postMessage', $message->channel);
    }

    /**
     * Determine whether the user can forward the message elsewhere.
     *
     * Forwarding copies a message somewhere its author never put it, so the
     * question is whether the user may read it in the first place: the *what may
     * I open* reading of the channel ACL, which lets a teammate forward out of a
     * public channel they never joined but not out of a private one. A system
     * notice is inert and carries nothing to quote, so it is not forwardable
     * even by someone who can see it.
     *
     * Where the copy may land is a separate question with a separate answer —
     * {@see \App\Rules\ForwardDestination} — because being able to read a
     * message says nothing about which channels the reader may post into.
     */
    public function forward(User $user, Message $message): bool
    {
        if ($message->isSystem()) {
            return false;
        }

        return $user->can('view', $message->channel);
    }

    /**
     * Determine whether the user can delete the message.
     *
     * The author may delete their own message only in a channel they can
     * currently participate in (still a member, channel not archived — the same
     * rule as posting). A team Admin+ may delete any message in the team as a
     * moderation action, deliberately independent of channel membership and
     * archived state so moderation reaches private channels the admin isn't in
     * and read-only archives. System notices are inert, so neither the recorded
     * actor nor a moderator may delete them.
     */
    public function delete(User $user, Message $message): bool
    {
        if ($message->isSystem()) {
            return false;
        }

        if ($message->user_id === $user->id && $user->can('postMessage', $message->channel)) {
            return true;
        }

        return $user->teamRole($message->channel->team)?->isAtLeast(TeamRole::Admin) ?? false;
    }
}
