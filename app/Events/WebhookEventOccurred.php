<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\WebhookEvent;
use App\Listeners\QueueWebhookDeliveries;
use App\Models\Channel;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A domain event happened in a channel that outgoing-webhook subscriptions may
 * want. This is the single emission abstraction: each Action seam dispatches one
 * of these with the already-built payload, and {@see QueueWebhookDeliveries}
 * fans it out to every matching subscription. It never broadcasts and carries no
 * viewer context — it is purely the producer side of the webhook pipeline.
 */
class WebhookEventOccurred
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $payload  The frozen, documented body shape
     *                                         for this event (the envelope `data`).
     */
    public function __construct(
        public readonly WebhookEvent $event,
        public readonly Channel $channel,
        public readonly array $payload,
    ) {}
}
