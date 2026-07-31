<?php

declare(strict_types=1);

namespace App\Actions\Integrations;

use App\Enums\AuditAction;
use App\Events\AuditableActionOccurred;
use App\Jobs\DeliverWebhook;
use App\Models\User;
use App\Models\WebhookDelivery;

/**
 * Re-fires one past delivery attempt by hand, queueing a fresh single-shot
 * {@see DeliverWebhook} for the envelope that was originally POSTed. The
 * receiver therefore sees the same envelope id — deliveries are at-least-once
 * and meant to be deduped on it — but a freshly computed signature, so a
 * rotated secret still verifies.
 *
 * The replay is inert with respect to subscription health (no failure streak,
 * no auto-disable, no retries), which is what makes it safe to offer on an
 * auto-disabled subscription as a way to verify a fixed endpoint.
 */
class ReplayWebhookDelivery
{
    /**
     * Queue the replay and record it in the workspace audit log. The caller is
     * responsible for rejecting an attempt that {@see WebhookDelivery::isReplayable()}
     * says has no envelope to re-send.
     */
    public function handle(User $actor, WebhookDelivery $delivery): void
    {
        $envelope = $delivery->envelope;
        assert($envelope !== null);

        $subscription = $delivery->subscription;

        dispatch(new DeliverWebhook($subscription->id, $envelope, isReplay: true));

        event(new AuditableActionOccurred($subscription->team, $actor, AuditAction::WebhookDeliveryReplayed, $subscription, [
            'subscription_name' => $subscription->name,
            'event_type' => $delivery->event_type,
            'event_id' => $delivery->event_id,
        ]));
    }
}
