<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\ScheduledMessageStatus;
use App\Models\ScheduledMessage;
use App\Models\User;

class ScheduledMessagePolicy
{
    /**
     * Determine whether the user can edit the scheduled message.
     *
     * Only the author may edit their own, and only while it is still pending —
     * a delivered or cancelled row is immutable.
     */
    public function update(User $user, ScheduledMessage $scheduledMessage): bool
    {
        return $this->managesPending($user, $scheduledMessage);
    }

    /**
     * Determine whether the user can cancel the scheduled message.
     *
     * Only the author may cancel their own, and only while it is still pending.
     */
    public function delete(User $user, ScheduledMessage $scheduledMessage): bool
    {
        return $this->managesPending($user, $scheduledMessage);
    }

    /**
     * Shared rule: the actor authored the row and it is still awaiting delivery.
     */
    private function managesPending(User $user, ScheduledMessage $scheduledMessage): bool
    {
        return $scheduledMessage->user_id === $user->id
            && $scheduledMessage->status === ScheduledMessageStatus::Pending;
    }
}
