<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Events\UserProfileUpdated;
use App\Models\User;

/**
 * Set a user's custom status, replacing any previous one.
 *
 * The three columns are always written together, so switching from a status
 * that expired at noon to one that never clears leaves no stale expiry behind.
 * The broadcast lets teammates' open clients pick the new emoji up without a
 * reload.
 */
final class SetUserStatus
{
    public function handle(User $user, string $emoji, ?string $text = null, ?string $expiresAt = null): void
    {
        $user->forceFill([
            'status_emoji' => $emoji,
            'status_text' => $text,
            'status_expires_at' => $expiresAt,
        ])->save();

        event(new UserProfileUpdated($user));
    }
}
