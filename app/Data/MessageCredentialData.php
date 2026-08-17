<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\MessageCredentialKind;
use App\Models\Message;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The credential that produced one message, so an admin reading a suspicious row
 * can revoke exactly that one.
 *
 * A bot holds one webhook per (bot, channel) pair and as many API tokens as it
 * has been minted, so naming the author is not enough: revoking on the strength
 * of it would take down every credential that bot holds. This names the one.
 *
 * It is **not** carried on every message payload. A credential's name is
 * admin-authored and routinely spells out operational detail ("Prod PagerDuty
 * bridge"), so it is resolved only for viewers who hold `manageIntegrations` on
 * the channel's team — the people who could act on it. Every other viewer, and
 * every viewer-free broadcast, sees null. See {@see MessageData::fromMessage()}.
 */
#[TypeScript]
final class MessageCredentialData extends Data
{
    public function __construct(
        public MessageCredentialKind $kind,
        public string $id,
        public string $name,
        /**
         * The bot whose detail page holds the rack this credential is revoked
         * from. Null for a webhook, which is revoked from the team-level
         * integrations index instead. The client needs it to build the link, and
         * it is answered here rather than inferred from the message's author so
         * the server stays the one authority on where a credential lives.
         */
        public ?string $botId,
    ) {}

    /**
     * Build the credential a message records, or null when it has none.
     *
     * Null on an ordinary human send, on a message that predates these columns,
     * and once the credential's own row is deleted — the message outlives its
     * credential by design, so an unresolvable reference degrades to no
     * attribution rather than to an error.
     *
     * A **human** personal access token resolves to null even for an admin. Only
     * its owner can revoke it and its name is their own private label, so naming
     * it here would disclose one person's wording while offering no action to
     * take on it. The column still records it, which keeps the row joinable for
     * support without putting it on the timeline.
     */
    public static function forMessage(Message $message): ?self
    {
        $webhook = $message->incomingWebhook;

        if ($webhook !== null) {
            return new self(
                kind: MessageCredentialKind::IncomingWebhook,
                id: $webhook->id,
                name: $webhook->name,
                botId: null,
            );
        }

        $token = $message->token;

        if (! $token instanceof PersonalAccessToken) {
            return null;
        }

        $bot = $token->tokenable;

        if (! $bot instanceof User || ! $bot->isBot()) {
            return null;
        }

        return new self(
            kind: MessageCredentialKind::ApiToken,
            id: (string) $token->getKey(),
            name: $token->name,
            botId: $bot->id,
        );
    }
}
