<?php

namespace App\Data;

use App\Models\IncomingWebhook;
use App\Models\Team;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * An incoming webhook as shown on the integrations home — which channel it posts
 * to, as which bot, and whether it is still active. The opaque URL is a secret
 * shown once at creation, so it is deliberately absent here (only its hash is
 * stored and it can never be re-displayed).
 */
#[TypeScript]
class IncomingWebhookData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $channelName,
        public string $botName,
        public bool $active,
        public string $createdAt,
    ) {}

    /**
     * Build the DTO from a webhook model. Its `channel` and `bot` should be
     * eager-loaded.
     */
    public static function fromModel(IncomingWebhook $webhook): self
    {
        return new self(
            id: $webhook->id,
            name: $webhook->name,
            channelName: $webhook->channel->name,
            botName: $webhook->bot->name,
            active: $webhook->revoked_at === null,
            createdAt: $webhook->created_at?->toISOString() ?? '',
        );
    }

    /**
     * The team's active incoming webhooks for the integrations home, newest first.
     *
     * @return array<int, self>
     */
    public static function forTeam(Team $team): array
    {
        return $team->incomingWebhooks()
            ->active()
            ->with(['channel', 'bot'])
            ->latest()
            ->get()
            ->map(fn (IncomingWebhook $webhook): self => self::fromModel($webhook))
            ->all();
    }
}
