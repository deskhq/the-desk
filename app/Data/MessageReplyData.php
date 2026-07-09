<?php

namespace App\Data;

use App\Models\Message;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class MessageReplyData extends Data
{
    /**
     * @param  array<int, MentionData>  $mentions
     */
    public function __construct(
        public string $id,
        public string $body,
        public string $authorName,
        public bool $isDeleted,
        public array $mentions,
    ) {}

    /**
     * Build the compact quote preview for a parent message.
     *
     * This is intentionally flat — it carries no nested `replyTo` — so a quote
     * never recurses into the chain of messages it answers. A soft-deleted
     * parent blanks its body and mentions, leaving only the `isDeleted` flag so
     * the client can render a "message deleted" stub.
     */
    public static function fromMessage(Message $message): self
    {
        $isDeleted = $message->trashed();

        return new self(
            id: $message->id,
            body: $isDeleted ? '' : $message->body,
            authorName: $message->user->name,
            isDeleted: $isDeleted,
            mentions: $isDeleted ? [] : $message->mentionedUsers->map(fn ($user) => MentionData::fromUser($user))->all(),
        );
    }
}
