<?php

namespace App\Actions\Channels;

use App\Enums\MessageReminderStatus;
use App\Events\MessageReminderDue;
use App\Models\MessageReminder;

class DispatchDueMessageReminders
{
    /**
     * Fire every reminder whose due time has arrived.
     *
     * Runs once per minute. Only pending rows that are due are claimed, so a
     * cleared reminder is never touched and a fired one never fires twice. Each
     * is flipped to Fired and its owner is signalled on their private channel so
     * the open workspace slides in the nudge; a user who is away picks it up from
     * the recomputed `firedReminders` prop on their next visit.
     */
    public function handle(): void
    {
        MessageReminder::query()
            ->due()
            ->orderBy('remind_at')
            ->get()
            ->each(function (MessageReminder $reminder): void {
                $reminder->update([
                    'status' => MessageReminderStatus::Fired,
                    'fired_at' => now(),
                ]);

                MessageReminderDue::dispatch($reminder->user_id);
            });
    }
}
