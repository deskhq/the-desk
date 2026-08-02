<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Events\UserProfileUpdated;
use App\Models\User;

/**
 * Resume a user's notifications, ending a manual pause early.
 *
 * Only the pause is cleared: the recurring quiet-hours schedule is a standing
 * preference and survives — resuming during quiet hours therefore leaves the
 * user in DND until the window ends, which is what the schedule asked for.
 */
class ResumeNotifications
{
    public function handle(User $user): void
    {
        $user->forceFill(['dnd_until' => null])->save();

        event(new UserProfileUpdated($user));
    }
}
