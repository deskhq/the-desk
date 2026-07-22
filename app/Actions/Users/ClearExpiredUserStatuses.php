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
     * The cursor can hand back a user who has since set a *new* status, so each
     * clear is conditional on the expiry still being the lapsed one this pass
     * read. A status replaced in that window is left alone — and neither counted
     * nor broadcast — rather than being wiped moments after the user set it.
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
                $updated = User::query()
                    ->whereKey($user->getKey())
                    ->where('status_expires_at', $user->status_expires_at)
                    ->update([
                        'status_emoji' => null,
                        'status_text' => null,
                        'status_expires_at' => null,
                    ]);

                if ($updated === 0) {
                    return;
                }

                event(new UserProfileUpdated($user->refresh()));

                $cleared++;
            });

        return $cleared;
    }
}
