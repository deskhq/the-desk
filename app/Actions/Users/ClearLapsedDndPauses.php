<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Events\UserProfileUpdated;
use App\Models\User;

class ClearLapsedDndPauses
{
    /**
     * Null out every manual do-not-disturb pause whose instant has passed, and
     * broadcast each clear so teammates' open clients drop the DND badge
     * without a reload.
     *
     * This is the eager half of expiry, mirroring {@see ClearExpiredUserStatuses}
     * for the custom status. Reads already treat a lapsed pause as over (see
     * {@see User::isDndActive()}), so this sweep is what makes the lapse
     * *propagate*: nothing else would tell a teammate sitting on an idle page
     * that the pause ended. Running it every minute keeps the wall-clock error
     * under the smallest offered preset.
     *
     * The cursor can hand back a user who has since started a *fresh* pause, so
     * each clear is conditional on the instant still being the lapsed one this
     * pass read. A pause replaced in that window is left alone — and neither
     * counted nor broadcast — rather than being wiped moments after the user
     * started it.
     *
     * @return int the number of pauses cleared
     */
    public function handle(): int
    {
        $cleared = 0;

        User::query()
            ->whereNotNull('dnd_until')
            ->where('dnd_until', '<=', now())
            ->cursor()
            ->each(function (User $user) use (&$cleared): void {
                $updated = User::query()
                    ->whereKey($user->getKey())
                    ->where('dnd_until', $user->dnd_until)
                    ->update(['dnd_until' => null]);

                if ($updated === 0) {
                    return;
                }

                event(new UserProfileUpdated($user->refresh()));

                $cleared++;
            });

        return $cleared;
    }
}
