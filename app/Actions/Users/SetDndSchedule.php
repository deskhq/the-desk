<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Events\UserProfileUpdated;
use App\Models\User;

/**
 * Set a user's recurring quiet-hours window.
 *
 * Enabling writes the window it was given; disabling flips the switch but keeps
 * the stored bounds, so re-enabling later remembers them. Either way the
 * broadcast lets teammates' open clients repaint the DND badge — a window that
 * covers this very moment flips the flag right now.
 */
final class SetDndSchedule
{
    public function handle(User $user, bool $enabled, ?string $startsAt = null, ?string $endsAt = null): void
    {
        $changes = ['dnd_schedule_enabled' => $enabled];

        // Bounds are only ever rewritten on an enable: a disable flips the
        // switch alone, so whatever it happens to carry can't clobber the
        // stored window the next enable is meant to remember. That is a rule of
        // the mutation, which is why it travels with it rather than staying at
        // whichever surface happened to ask.
        if ($enabled && $startsAt !== null && $endsAt !== null) {
            $changes['dnd_starts_at'] = $startsAt;
            $changes['dnd_ends_at'] = $endsAt;
        }

        $user->forceFill($changes)->save();

        event(new UserProfileUpdated($user));
    }
}
