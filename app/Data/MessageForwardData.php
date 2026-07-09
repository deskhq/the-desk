<?php

namespace App\Data;

use App\Models\Message;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class MessageForwardData extends Data
{
    /**
     * @param  array<int, MentionData>  $mentions
     */
    public function __construct(
        public string $id,
        public string $body,
        public string $authorName,
        public string $channelName,
        public bool $isDeleted,
        public array $mentions,
    ) {}

    /**
     * Build the compact quote of a forwarded source message.
     *
     * Like {@see MessageReplyData} this is intentionally flat — it carries no
     * nested reference — but it also names the source channel so the forward can
     * render its "Forwarded from #name" attribution. The `user` and `channel`
     * relations are expected to be eager-loaded. A soft-deleted source blanks its
     * body and mentions, leaving only the `isDeleted` flag so the client can
     * render a "message deleted" stub.
     */
    public static function fromMessage(Message $message): self
    {
        $isDeleted = $message->trashed();

        return new self(
            id: $message->id,
            body: $isDeleted ? '' : $message->body,
            authorName: $message->user->name,
            channelName: $message->channel->name,
            isDeleted: $isDeleted,
            mentions: $isDeleted ? [] : $message->mentionedUsers->map(fn ($user) => MentionData::fromUser($user))->all(),
        );
    }
}
