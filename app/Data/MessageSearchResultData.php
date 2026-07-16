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
        public string $snippet,
        public string $teamId,
        public string $teamName,
        public string $teamSlug,
    ) {}

    /**
     * Build the DTO from a search hit: the matched message and its highlighted,
     * XSS-safe snippet.
     *
     * The message's `channel.team`, `user`, and `mentionedUsers` relations should
     * be eager-loaded so rendering the result, its workspace tag, and its
     * cross-team jump link avoid N+1 queries.
     */
    public static function fromHit(MessageSearchHit $hit): self
    {
        return self::fromMessage($hit->message, $hit->snippet);
    }

    /**
     * Build the DTO from a search-matched Message and its snippet.
     *
     * The owning team travels with each result so a cross-team ("All workspaces")
     * search can tag the result and build a jump link to the message's own team.
     */
    public static function fromMessage(Message $message, string $snippet): self
    {
        return new self(
            message: MessageData::fromMessage($message),
            channelName: $message->channel->name,
            channelSlug: $message->channel->slug,
            snippet: $snippet,
            teamId: $message->channel->team->id,
            teamName: $message->channel->team->name,
            teamSlug: $message->channel->team->slug,
        );
    }
}
