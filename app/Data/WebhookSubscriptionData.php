<?php

namespace App\Data;

use App\Models\Team;
use App\Models\WebhookSubscription;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * An outgoing-webhook subscription as shown on the integrations home — its
 * target, subscribed events, and delivery health (active vs. auto-disabled). The
 * signing secret is never included; the detail page exposes rotate/re-enable.
 */
#[TypeScript]
class WebhookSubscriptionData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $url,
        /** @var array<int, string> */
        public array $events,
        public string $status,
        public ?string $disabledAt,
        public ?string $lastSuccessAt,
        public string $createdAt,
    ) {}

    /**
     * Build the DTO from a subscription model.
     */
    public static function fromModel(WebhookSubscription $subscription): self
    {
        /** @var array<int, string> $events */
        $events = $subscription->events ?? [];

        return new self(
            id: $subscription->id,
            name: $subscription->name,
            url: $subscription->url,
            events: array_values($events),
            status: $subscription->status->value,
            disabledAt: $subscription->disabled_at?->toISOString(),
            lastSuccessAt: $subscription->last_success_at?->toISOString(),
            createdAt: $subscription->created_at?->toISOString() ?? '',
        );
    }

    /**
     * The team's outgoing subscriptions for the integrations home, newest first.
     *
     * @return array<int, self>
     */
    public static function forTeam(Team $team): array
    {
        return $team->webhookSubscriptions()
            ->latest()
            ->get()
            ->map(fn (WebhookSubscription $subscription): self => self::fromModel($subscription))
            ->all();
    }
}
