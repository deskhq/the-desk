<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Models\User;
use App\Support\ExpirySweep;

class ClearLapsedDndScheduleSnoozes
{
    /**
     * Null out every quiet-hours snooze whose instant has passed, and
     * broadcast each clear so teammates' open clients repaint without a
     * reload.
     *
     * This is the eager half of expiry. Reads already treat a lapsed snooze as
     * over (see {@see UserAvailability::isDnd()}); this sweep is what makes the lapse
     * *propagate* — and keeps the column from holding a stale instant the next
     * snooze would have to overwrite blind.
     *
     * The walk, and the compare-and-swap that spares a snooze set afresh
     * mid-pass, are {@see ExpirySweep}'s.
     *
     * @return int the number of snoozes cleared
     */
    public function handle(): int
    {
        return ExpirySweep::clearLapsedProfileInstant(User::query(), 'dnd_schedule_snoozed_until');
    }
}
