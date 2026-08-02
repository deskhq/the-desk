<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Events\UserProfileUpdated;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Pause a user's notifications until an instant, replacing any pause already
 * running.
 *
 * The pause lives on the row rather than in the client so it survives a reload,
 * a new device, and a restart until it lapses. The broadcast tells teammates'
 * open clients to paint the DND badge without a reload.
 *
 * The sibling of {@see ClearLapsedDndPauses}, which is the same column's eager
 * expiry: the two writes to `dnd_until` that are not this one's undo both live
 * beside it now rather than in HTTP glue.
 */
class PauseNotifications
{
    public function handle(User $user, CarbonInterface $until): void
    {
        $user->forceFill(['dnd_until' => $until])->save();

        event(new UserProfileUpdated($user));
    }
}
