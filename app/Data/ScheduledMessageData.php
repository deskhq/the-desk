<?php

namespace App\Data;

use App\Models\ScheduledMessage;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ScheduledMessageData extends Data
{
    public function __construct(
        public string $id,
        public string $clientUuid,
        public string $body,
        public string $sendAt,
        public string $createdAt,
        public ?MessageReplyData $replyTo,
    ) {}

    /**
     * Build the DTO from a ScheduledMessage model.
     *
     * `send_at` is serialized as a UTC ISO 8601 instant; the client renders it in
     * the viewer's timezone. The `replyTo` relation (with its own `user` and
     * `mentionedUsers`) should be eager-loaded when the message quotes a parent;
     * a since-deleted parent still resolves so the quote can render a stub.
     *
     * `clientUuid` is the id the composer minted for the send, echoed back so the
     * client can point at the row it just created — Undo on "Message scheduled"
     * needs to cancel *that* row, and picking the newest by `createdAt` goes
     * wrong the moment two schedules land in the same second (#978).
     */
    public static function fromScheduledMessage(ScheduledMessage $scheduled): self
    {
        return new self(
            id: $scheduled->id,
            clientUuid: $scheduled->client_uuid,
            body: $scheduled->body,
            sendAt: $scheduled->send_at->toIso8601String(),
            createdAt: $scheduled->created_at->toIso8601String(),
            replyTo: $scheduled->replyTo !== null
                ? MessageReplyData::fromMessage($scheduled->replyTo)
                : null,
        );
    }
}
