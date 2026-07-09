<?php

namespace App\Data;

use App\Models\Message;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class MessageSearchResultData extends Data
{
    public function __construct(
        public MessageData $message,
        public string $channelName,
        public string $channelSlug,
    ) {}

    /**
     * Build the DTO from a search-matched Message.
     *
     * The message's `channel`, `user`, and `mentionedUsers` relations should be
     * eager-loaded so rendering the result and its jump link avoids N+1 queries.
     */
    public static function fromMessage(Message $message): self
    {
        return new self(
            message: MessageData::fromMessage($message),
            channelName: $message->channel->name,
            channelSlug: $message->channel->slug,
        );
    }
}
