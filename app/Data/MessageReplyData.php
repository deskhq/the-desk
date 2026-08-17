<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\Message;
use App\Models\User;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class MessageReplyData extends Data
{
    /**
     * @param  array<int, MentionData>  $mentions
     */
    public function __construct(
        public string $id,
        public string $body,
        public string $authorName,
        /**
         * Whether the quoted author is a bot, so the quote can badge it. Carried
         * because a quote hands the client a bare name string: without this an
         * overridden name would render here with nothing marking it non-human.
         */
        public bool $authorIsBot,
        /** The display identity the quoted message asked for, if any. */
        public ?AuthorOverrideData $authorOverride,
        public bool $isDeleted,
        public array $mentions,
    ) {}

    /**
     * Build the compact quote preview for a parent message.
     *
     * This is intentionally flat — it carries no nested `replyTo` — so a quote
     * never recurses into the chain of messages it answers. A soft-deleted
     * parent blanks its body and mentions, leaving only the `isDeleted` flag so
     * the client can render a "message deleted" stub. The author's identity
     * survives that: a tombstone still names who was quoted, so its `authorIsBot`
     * marker and override travel with it.
     */
    public static function fromMessage(Message $message): self
    {
        $isDeleted = $message->trashed();

        return new self(
            id: $message->id,
            body: $isDeleted ? '' : $message->body,
            authorName: $message->user->name,
            authorIsBot: $message->user->isBot(),
            authorOverride: AuthorOverrideData::forMessage($message),
            isDeleted: $isDeleted,
            mentions: $isDeleted ? [] : $message->mentionedUsers->map(fn (User $user): MentionData => MentionData::fromUser($user))->all(),
        );
    }
}
