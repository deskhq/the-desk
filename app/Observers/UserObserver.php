<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\SecurityEventType;
use App\Models\User;
use App\Support\SecurityEventRecorder;

class UserObserver
{
    /**
     * Handle the User "updated" event, auditing directory (de)activation.
     *
     * A change to `deactivated_at` is the single choke point every deprovision
     * path flows through — the SCIM DELETE and create paths via
     * App\Actions\Sso\SetSsoUserActivation, and the PUT/PATCH `active` writes via
     * the attribute mapper that bypass it — so recording the transition here
     * captures a deactivation or reactivation regardless of its source. The
     * event only fires on a genuine change, so no-op idempotent pushes stay
     * silent.
     */
    public function updated(User $user): void
    {
        if (! $user->wasChanged('deactivated_at')) {
            return;
        }

        app(SecurityEventRecorder::class)->record(
            $user,
            $user->deactivated_at === null
                ? SecurityEventType::AccountReactivated
                : SecurityEventType::AccountDeactivated,
        );
    }
}
