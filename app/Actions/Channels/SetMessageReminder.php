<?php

namespace App\Actions\Channels;

use App\Enums\MessageReminderStatus;
use App\Models\Message;
use App\Models\MessageReminder;
use App\Models\User;
use Illuminate\Support\Carbon;

class SetMessageReminder
{
    /**
     * Arm a personal reminder for a user on a message, due at `$remindAt`.
     *
     * A user keeps at most one reminder per message, so setting one again — to
     * change the time, or to snooze a fired nudge — reuses the existing row and
     * re-arms it back to pending rather than stacking a second reminder.
     */
    public function handle(User $user, Message $message, Carbon $remindAt): MessageReminder
    {
        return MessageReminder::updateOrCreate(
            [
                'user_id' => $user->id,
                'message_id' => $message->id,
            ],
            [
                'remind_at' => $remindAt,
                'status' => MessageReminderStatus::Pending,
                'fired_at' => null,
            ],
        );
    }
}
