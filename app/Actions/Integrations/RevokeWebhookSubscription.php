<?php

declare(strict_types=1);

namespace App\Actions\Integrations;

use App\Enums\AuditAction;
use App\Events\AuditableActionOccurred;
use App\Models\User;
use App\Models\WebhookSubscription;
use Illuminate\Support\Facades\DB;

/**
 * Revokes a webhook subscription and records the revocation in the audit log.
 * Deleting the row (and its delivery-log children) stops all future delivery
 * immediately.
 */
class RevokeWebhookSubscription
{
    public function handle(User $actor, WebhookSubscription $subscription): void
    {
        $team = $subscription->team;
        $name = $subscription->name;

        DB::transaction(function () use ($team, $actor, $subscription, $name): void {
            $subscription->delete();

            event(new AuditableActionOccurred($team, $actor, AuditAction::WebhookSubscriptionRevoked, $subscription, [
                'subscription_name' => $name,
            ]));
        });
    }
}
