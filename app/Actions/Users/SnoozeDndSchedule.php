<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Events\UserProfileUpdated;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Snooze a user's quiet-hours schedule until the running window next closes.
 *
 * The lapse instant is the server's own computation from the stored bounds —
 * the caller supplies nothing — so the snooze can never outlive tonight's
 * window: once it passes the schedule resumes on its own, with no re-enable
 * step. Outside the window there is nothing to suppress, so the request changes
 * nothing and announces nothing. The broadcast tells teammates' open clients to
 * drop the DND badge without a reload.
 *
 * @return bool whether a window was actually running to be snoozed
 */
class SnoozeDndSchedule
{
    public function handle(User $user): bool
    {
        $closesAt = $user->availability()->scheduleClosesAt();

        if (! $closesAt instanceof CarbonInterface) {
            return false;
        }

        $user->forceFill(['dnd_schedule_snoozed_until' => $closesAt])->save();

        event(new UserProfileUpdated($user));

        return true;
    }
}
