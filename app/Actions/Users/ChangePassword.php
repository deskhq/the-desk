<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Enums\SecurityEventType;
use App\Events\SecurityEventOccurred;
use App\Models\User;
use App\Support\SessionRegistry;

/**
 * Replaces a user's password from the in-app security settings.
 *
 * The current-password check and confirmation rules belong to the request; by
 * the time this runs the change is authorized. Fortify's own reset flow fires
 * `PasswordReset`, but an in-app change fires no framework event, so the
 * security activity is raised here — next to the mutation, so any future
 * surface that changes a password records it too.
 *
 * Changing the password is what a user does when they suspect the account is
 * compromised, so every other session is revoked alongside it (the same shape
 * as App\Actions\Sso\SetSsoUserActivation deactivating an account). Only the
 * device making the change is spared; the rest are bounced to the login screen
 * on their next request by App\Http\Middleware\TrackActiveSession.
 */
final readonly class ChangePassword
{
    public function __construct(private SessionRegistry $sessions) {}

    /**
     * Change the password, returning how many other sessions were revoked.
     *
     * The caller owns the acting session, so it passes in the id to spare and
     * is responsible for regenerating it once the change lands.
     */
    public function handle(User $user, string $password, string $keepSessionId): int
    {
        $user->update(['password' => $password]);

        event(new SecurityEventOccurred($user, SecurityEventType::PasswordChanged));

        return $this->sessions->forgetOthers((string) $user->getKey(), $keepSessionId);
    }
}
