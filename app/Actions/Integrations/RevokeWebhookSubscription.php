<?php

declare(strict_types=1);

namespace App\Actions\Integrations;

use App\Enums\AuditAction;
use App\Models\User;
use App\Models\WebhookSubscription;
use App\Support\AuditRecorder;

/**
 * Revokes a webhook subscription and records the revocation in the audit log.
 * Deleting the row (and its delivery-log children) stops all future delivery
 * immediately.
 */
class RevokeWebhookSubscription
{
    public function __construct(private readonly AuditRecorder $recorder) {}

    public function handle(User $actor, WebhookSubscription $subscription): void
    {
        $team = $subscription->team;
        $name = $subscription->name;

        $subscription->delete();

        $this->recorder->record($team, $actor, AuditAction::WebhookSubscriptionRevoked, $subscription, [
            'subscription_name' => $name,
        ]);
    }
}
