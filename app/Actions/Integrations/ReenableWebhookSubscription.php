<?php

declare(strict_types=1);

namespace App\Actions\Integrations;

use App\Enums\AuditAction;
use App\Enums\WebhookSubscriptionStatus;
use App\Models\User;
use App\Models\WebhookSubscription;
use App\Support\AuditRecorder;

/**
 * Re-enables an auto-disabled webhook subscription and records it in the audit
 * log. The failure streak is cleared so delivery resumes with a clean slate; the
 * signing secret is untouched (rotate it separately). A no-op flag lets the
 * caller keep the response idempotent for an already-active subscription.
 */
class ReenableWebhookSubscription
{
    public function __construct(private readonly AuditRecorder $recorder) {}

    public function handle(User $actor, WebhookSubscription $subscription): void
    {
        if ($subscription->isActive()) {
            return;
        }

        $subscription->forceFill([
            'status' => WebhookSubscriptionStatus::Active,
            'consecutive_failures' => 0,
            'disabled_at' => null,
        ])->save();

        $this->recorder->record($subscription->team, $actor, AuditAction::WebhookSubscriptionReenabled, $subscription, [
            'subscription_name' => $subscription->name,
        ]);
    }
}
