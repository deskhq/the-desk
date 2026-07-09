<?php

namespace App\Data;

use App\Models\Message;
use App\Models\User;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class MessageData extends Data
{
    /**
     * @param  array<int, MentionData>  $mentions
     * @param  array<int, MentionData>  $threadParticipants
     */
    public function __construct(
        public string $id,
        public string $clientUuid,
        public string $body,
        public UserData $user,
        public string $createdAt,
        public ?string $editedAt,
        public bool $isDeleted,
        public array $mentions,
        public ?MessageReplyData $replyTo,
        public ?string $threadRootId,
        public bool $sentToChannel,
        public int $threadReplyCount,
        public ?string $threadLastReplyAt,
        public array $threadParticipants,
    ) {}

    /**
     * Build the DTO from a Message model.
     *
     * The message's `user` and `mentionedUsers` relations should be eager-loaded
     * to avoid N+1 queries. A soft-deleted message renders as a tombstone: its
     * body and mentions are never sent to the client, only the `isDeleted` flag.
     *
     * The `replyTo` relation is expected to be eager-loaded (with its own `user`
     * and `mentionedUsers`) when the message quotes a parent; a deleted parent
     * still resolves so the quote can render a stub. A tombstone carries no
     * quote of its own — a deleted message shows only its own placeholder.
     *
     * Thread aggregates (`threadReplyCount`, `threadLastReplyAt`,
     * `threadParticipants`) are structural, so they survive a soft delete — a
     * deleted root still shows its "N replies" affordance. `threadParticipants`
     * resolves from the eager-loaded relation when present, empty otherwise.
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
            mentions: $isDeleted ? [] : $message->mentionedUsers->map(fn (User $user) => MentionData::fromUser($user))->all(),
            replyTo: ! $isDeleted && $message->replyTo !== null
                ? MessageReplyData::fromMessage($message->replyTo)
                : null,
            threadRootId: $message->thread_root_id,
            sentToChannel: $message->sent_to_channel,
            threadReplyCount: $message->reply_count,
            threadLastReplyAt: $message->last_reply_at?->toIso8601String(),
            threadParticipants: $message->relationLoaded('threadParticipants')
                ? $message->threadParticipants->map(fn (User $user) => MentionData::fromUser($user))->all()
                : [],
        );
    }
}
