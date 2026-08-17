<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Models\User;
use App\Support\ExpirySweep;

final class ClearLapsedDndPauses
{
    /**
     * Null out every manual do-not-disturb pause whose instant has passed, and
     * broadcast each clear so teammates' open clients drop the DND badge
     * without a reload.
     *
     * This is the eager half of expiry. Reads already treat a lapsed pause as
     * over (see {@see UserAvailability::isDnd()}), so this sweep is what makes the
     * lapse *propagate*: nothing else would tell a teammate sitting on an idle
     * page that the pause ended. Running it every minute keeps the wall-clock
     * error under the smallest offered preset.
     *
     * The walk, and the compare-and-swap that spares a pause started afresh
     * mid-pass, are {@see ExpirySweep}'s.
     *
     * @return int the number of pauses cleared
     */
    public function handle(): int
    {
        return ExpirySweep::clearLapsedProfileInstant(User::query(), 'dnd_until');
    }
}
