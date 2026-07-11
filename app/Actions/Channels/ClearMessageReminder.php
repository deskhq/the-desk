<?php

namespace App\Actions\Channels;

use App\Models\MessageReminder;

class ClearMessageReminder
{
    /**
     * Clear a reminder outright.
     *
     * Reminders are personal and transient — a pending one the user no longer
     * wants, or a fired nudge they have acknowledged — so the row is deleted
     * rather than kept. The unique (user, message) index is freed, so the user
     * can set a fresh reminder on the same message later.
     */
    public function handle(MessageReminder $reminder): void
    {
        $reminder->delete();
    }
}
