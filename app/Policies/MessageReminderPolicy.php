<?php

namespace App\Policies;

use App\Models\MessageReminder;
use App\Models\User;

class MessageReminderPolicy
{
    /**
     * Determine whether the user can clear the reminder.
     *
     * Reminders are personal, so only the user who set one may clear it.
     */
    public function delete(User $user, MessageReminder $reminder): bool
    {
        return $reminder->user_id === $user->id;
    }
}
