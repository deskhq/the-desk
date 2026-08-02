<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Events\UserProfileUpdated;
use App\Models\User;

/**
 * Clear a user's custom status.
 *
 * The deliberate half of the same clear {@see ClearExpiredUserStatuses} makes
 * when an expiry passes, so both null the same three columns and announce it
 * the same way.
 */
class ClearUserStatus
{
    public function handle(User $user): void
    {
        $user->forceFill([
            'status_emoji' => null,
            'status_text' => null,
            'status_expires_at' => null,
        ])->save();

        event(new UserProfileUpdated($user));
    }
}
