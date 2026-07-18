<?php

namespace App\Data;

use App\Models\Channel;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The full outgoing-subscription detail — the subscription, the channels it is
 * scoped to (null when it listens to every channel), and its recent delivery
 * log — feeding the health/detail page.
 */
#[TypeScript]
class WebhookSubscriptionDetailData extends Data
{
    public function __construct(
        public WebhookSubscriptionData $subscription,
        /** @var array<int, MentionData>|null */
        public ?array $channels,
        /** @var array<int, WebhookDeliveryData> */
        public array $deliveries,
    ) {}

    /**
     * Build the DTO from a subscription, resolving its channel allow-list to
     * names and loading its most recent delivery attempts.
     */
    public static function fromModel(WebhookSubscription $subscription, int $deliveryLimit = 20): self
    {
        return new self(
            subscription: WebhookSubscriptionData::fromModel($subscription),
            channels: self::channels($subscription),
            deliveries: $subscription->deliveries()
                ->latest()
                ->limit($deliveryLimit)
                ->get()
                ->map(fn (WebhookDelivery $delivery): WebhookDeliveryData => WebhookDeliveryData::fromModel($delivery))
                ->all(),
        );
    }

    /**
     * The subscription's channel allow-list as id/name pairs, or null when it
     * is not scoped to any channel (it then listens to the whole workspace).
     *
     * @return array<int, MentionData>|null
     */
    private static function channels(WebhookSubscription $subscription): ?array
    {
        $channelIds = $subscription->channel_ids;

        if ($channelIds === null || $channelIds === []) {
            return null;
        }

        return Channel::query()
            ->whereIn('id', $channelIds)
            ->get(['id', 'name'])
            ->map(fn (Channel $channel): MentionData => new MentionData($channel->id, $channel->name))
            ->all();
    }
}
