<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Enums\SecurityEventType;
use App\Events\SecurityEventOccurred;
use App\Models\User;

/**
 * Replaces a user's password from the in-app security settings.
 *
 * The current-password check and confirmation rules belong to the request; by
 * the time this runs the change is authorized. Fortify's own reset flow fires
 * `PasswordReset`, but an in-app change fires no framework event, so the
 * security activity is raised here — next to the mutation, so any future
 * surface that changes a password records it too.
 */
class ChangePassword
{
    public function handle(User $user, string $password): void
    {
        $user->update(['password' => $password]);

        event(new SecurityEventOccurred($user, SecurityEventType::PasswordChanged));
    }
}
