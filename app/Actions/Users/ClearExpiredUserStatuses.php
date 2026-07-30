<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Models\User;
use App\Support\ExpirySweep;

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
     * The walk, and the compare-and-swap that spares a status set afresh
     * mid-pass, are {@see ExpirySweep}'s. Only the emoji and text riding along
     * with the expiry column are this sweeper's own.
     *
     * @return int the number of statuses cleared
     */
    public function handle(): int
    {
        return ExpirySweep::clearLapsedProfileInstant(
            User::query()->whereNotNull('status_emoji'),
            'status_expires_at',
            ['status_emoji', 'status_text'],
        );
    }
}
