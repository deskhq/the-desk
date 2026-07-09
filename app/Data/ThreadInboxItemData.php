<?php

namespace App\Data;

use App\Models\Message;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ThreadInboxItemData extends Data
{
    public function __construct(
        public MessageData $root,
        public string $channelName,
        public string $channelSlug,
    ) {}

    /**
     * Build the DTO from a followed thread's root message.
     *
     * The root's `channel`, `user`, `mentionedUsers`, and `threadParticipants`
     * relations should be eager-loaded, and the row annotated with the viewer's
     * thread read-state (see {@see Message::scopeWithThreadReadState()}), so the
     * inbox renders each row and its unread dot without an N+1.
     */
    public static function fromMessage(Message $message): self
    {
        return new self(
            root: MessageData::fromMessage($message),
            channelName: $message->channel->name,
            channelSlug: $message->channel->slug,
        );
    }
}
