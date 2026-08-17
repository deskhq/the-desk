<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\SecurityEventType;
use App\Events\SecurityEventOccurred;
use App\Models\User;

final class UserObserver
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

        event(new SecurityEventOccurred(
            $user,
            $user->deactivated_at === null
                ? SecurityEventType::AccountReactivated
                : SecurityEventType::AccountDeactivated,
        ));
    }

    /**
     * Handle the User "deleting" event, dropping the account's API tokens.
     *
     * Sanctum's `personal_access_tokens` addresses its owner through a
     * polymorphic pair (`tokenable_type` + `tokenable_id`) with no foreign key,
     * so no cascade reaches these rows the way one reaches a bot's incoming
     * webhooks or channel memberships. Left alone they outlive their owner
     * forever, in the table every API request's token lookup scans.
     *
     * Sweeping here rather than in a caller covers every deletion path at once
     * — {@see App\Actions\Integrations\DeleteBot} for a bot and
     * {@see App\Support\AccountDeleter} for a human — and runs inside whatever
     * transaction that caller opened, since it fires from `delete()` itself.
     *
     * The rows are already inert by the time they are swept: Sanctum resolves a
     * token's `tokenable` on every request and refuses when it is gone, so this
     * is hygiene, not a live credential being closed off. Deleting them nulls
     * `messages.token_id` on anything the tokens posted, exactly as revoking a
     * token by hand already does — the attribution thins, the messages stay.
     */
    public function deleting(User $user): void
    {
        $user->tokens()->delete();
    }
}
