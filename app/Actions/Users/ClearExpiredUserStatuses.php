<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Events\UserProfileUpdated;
use App\Models\User;

class ClearExpiredUserStatuses
{
    /**
     * Null out every custom status whose expiry has passed, and broadcast each
     * clear so teammates' open clients drop the emoji without a reload.
     *
     * This is the eager half of expiry. Reads already treat a lapsed status as
     * absent (see {@see User::hasLiveStatus()}), so this sweep is what makes the
     * lapse *propagate*: nothing else would tell a teammate sitting on an idle
     * page that the meeting is over. Running it every minute keeps the wall-clock
     * error under the smallest offered preset.
     *
     * @return int the number of statuses cleared
     */
    public function handle(): int
    {
        $cleared = 0;

        User::query()
            ->whereNotNull('status_emoji')
            ->whereNotNull('status_expires_at')
            ->where('status_expires_at', '<=', now())
            ->cursor()
            ->each(function (User $user) use (&$cleared): void {
                $user->forceFill([
                    'status_emoji' => null,
                    'status_text' => null,
                    'status_expires_at' => null,
                ])->save();

                event(new UserProfileUpdated($user));

                $cleared++;
            });

        return $cleared;
    }
}
