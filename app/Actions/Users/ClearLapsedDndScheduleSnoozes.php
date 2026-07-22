<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Events\UserProfileUpdated;
use App\Models\User;

class ClearLapsedDndScheduleSnoozes
{
    /**
     * Null out every quiet-hours snooze whose instant has passed, and
     * broadcast each clear so teammates' open clients repaint without a
     * reload.
     *
     * This is the eager half of expiry, mirroring {@see ClearLapsedDndPauses}
     * for the manual pause. Reads already treat a lapsed snooze as over (see
     * {@see User::isDndActive()}); this sweep is what makes the lapse
     * *propagate* — and keeps the column from holding a stale instant the next
     * snooze would have to overwrite blind.
     *
     * The cursor can hand back a user who has since snoozed *afresh*, so each
     * clear is conditional on the instant still being the lapsed one this pass
     * read. A snooze replaced in that window is left alone — and neither
     * counted nor broadcast — rather than being wiped moments after the user
     * set it.
     *
     * @return int the number of snoozes cleared
     */
    public function handle(): int
    {
        $cleared = 0;

        User::query()
            ->whereNotNull('dnd_schedule_snoozed_until')
            ->where('dnd_schedule_snoozed_until', '<=', now())
            ->cursor()
            ->each(function (User $user) use (&$cleared): void {
                $updated = User::query()
                    ->whereKey($user->getKey())
                    ->where('dnd_schedule_snoozed_until', $user->dnd_schedule_snoozed_until)
                    ->update(['dnd_schedule_snoozed_until' => null]);

                if ($updated === 0) {
                    return;
                }

                event(new UserProfileUpdated($user->refresh()));

                $cleared++;
            });

        return $cleared;
    }
}
