<?php

declare(strict_types=1);

namespace App\Actions\Sso;

use App\Models\User;
use App\Support\SessionRegistry;

/**
 * Toggles a directory user's activation, the app side of SCIM deprovisioning.
 *
 * Deactivation tombstones the account (sets `deactivated_at`) rather than
 * deleting it, so authored history is retained, and revokes every active session
 * so access is cut immediately. Reactivation reverses the flag on a subsequent
 * `active: true`. Enforcement of the revoked state on future requests lives in
 * App\Http\Middleware\EnsureUserIsActive.
 */
class SetSsoUserActivation
{
    public function __construct(private readonly SessionRegistry $sessions) {}

    /**
     * Deactivate the account and terminate its sessions.
     *
     * A no-op timestamp refresh is avoided when the account is already
     * deactivated, so the original deactivation moment is preserved.
     */
    public function deactivate(User $user): void
    {
        if ($user->isDeactivated()) {
            return;
        }

        $user->forceFill(['deactivated_at' => now()])->save();

        $this->sessions->flush((string) $user->getKey());
    }

    /**
     * Reactivate a previously deactivated account.
     */
    public function reactivate(User $user): void
    {
        if (! $user->isDeactivated()) {
            return;
        }

        $user->forceFill(['deactivated_at' => null])->save();
    }
}
