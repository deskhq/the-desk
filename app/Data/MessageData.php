<?php

namespace App\Data;

use App\Models\Message;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class MessageData extends Data
{
    public function __construct(
        public string $id,
        public string $clientUuid,
        public string $body,
        public UserData $user,
        public string $createdAt,
        public ?string $editedAt,
        public bool $isDeleted,
    ) {}

    /**
     * Build the DTO from a Message model.
     *
     * The message's `user` relation should be eager-loaded to avoid N+1 queries.
     * A soft-deleted message renders as a tombstone: its body is never sent to
     * the client, only the `isDeleted` flag.
     */
    public static function fromMessage(Message $message): self
    {
        $isDeleted = $message->trashed();

        return new self(
            id: $message->id,
            clientUuid: $message->client_uuid,
            body: $isDeleted ? '' : $message->body,
            user: UserData::fromUser($message->user),
            createdAt: $message->created_at->toIso8601String(),
            editedAt: $message->edited_at?->toIso8601String(),
            isDeleted: $isDeleted,
        );
    }
}
