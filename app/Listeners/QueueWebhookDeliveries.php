<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\WebhookSubscriptionStatus;
use App\Events\WebhookEventOccurred;
use App\Jobs\DeliverWebhook;
use App\Models\WebhookSubscription;
use Illuminate\Support\Str;

/**
 * Fans a {@see WebhookEventOccurred} out to every active subscription in the
 * channel's team that listens for it, queuing one {@see DeliverWebhook} job per
 * match. Auto-discovered (the app has no EventServiceProvider), and runs inline
 * — it only enqueues, so the HTTP calls themselves stay on the queue.
 *
 * The whole pipeline is gated on the integrations platform toggle: with it off,
 * no subscription can even be created, but disabling it after the fact also
 * stops delivery immediately.
 */
class QueueWebhookDeliveries
{
    /**
     * Handle the event.
     */
    public function handle(WebhookEventOccurred $event): void
    {
        if (! config('integrations.enabled')) {
            return;
        }

        $subscriptions = WebhookSubscription::query()
            ->where('team_id', $event->channel->team_id)
            ->where('status', WebhookSubscriptionStatus::Active)
            ->get()
            ->filter(fn (WebhookSubscription $subscription): bool => $subscription->listensFor($event->event, $event->channel->id));

        if ($subscriptions->isEmpty()) {
            return;
        }

        $envelope = [
            'id' => (string) Str::uuid(),
            'type' => $event->event->value,
            'created_at' => now()->toIso8601String(),
            'data' => $event->payload,
        ];

        foreach ($subscriptions as $subscription) {
            dispatch(new DeliverWebhook($subscription->id, $envelope));
        }
    }
}
