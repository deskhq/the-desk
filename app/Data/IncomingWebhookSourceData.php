<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\Message;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The incoming webhook that produced one message, so an admin reading a
 * suspicious row can revoke exactly that credential.
 *
 * A bot holds one webhook per (bot, channel) pair, so naming the author is not
 * enough: revoking on the strength of it would take down every URL that bot
 * holds. This names the one.
 *
 * It is **not** carried on every message payload. A webhook's name is
 * admin-authored and routinely spells out operational detail ("Prod PagerDuty
 * bridge"), so it is resolved only for viewers who hold `manageIntegrations` on
 * the channel's team — the people who could act on it. Every other viewer, and
 * every viewer-free broadcast, sees null. See {@see MessageData::fromMessage()}.
 */
#[TypeScript]
class IncomingWebhookSourceData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
    ) {}

    /**
     * Build the source a message records, or null when it has none.
     *
     * Null on every non-ingest path, on a webhook message that predates the
     * column, and once the webhook row itself is deleted — the message outlives
     * its credential by design, so an unresolvable id degrades to no attribution
     * rather than to an error.
     */
    public static function forMessage(Message $message): ?self
    {
        $webhook = $message->incomingWebhook;

        return $webhook === null ? null : new self(id: $webhook->id, name: $webhook->name);
    }
}
