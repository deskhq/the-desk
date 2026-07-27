<?php

namespace App\Data;

use App\Models\Message;
use App\Models\User;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ThreadInboxItemData extends Data
{
    public function __construct(
        public MessageData $root,
        public string $channelName,
        public string $channelSlug,
        public bool $isDirectMessage,
        public ?MentionData $dmParticipant = null,
    ) {}

    /**
     * Build the DTO from a followed thread's root message.
     *
     * The root's `channel` (with its `members`), `user`, `mentionedUsers`, and
     * `threadParticipants` relations should be eager-loaded, and the row annotated
     * with the viewer's thread read-state (see
     * {@see Message::scopeWithThreadReadState()}), so the panel renders each card,
     * its unread border and its new-replies line without an N+1.
     *
     * The channel name is viewer-relative, exactly as it is in a search result: a
     * DM has no stored name, so the viewer's counterpart stands in where a channel
     * name would appear, and `dmParticipant` carries that counterpart so a 1:1 card
     * can draw their avatar in place of the `#`.
     */
    public static function fromMessage(Message $message, User $viewer): self
    {
        $channel = $message->channel;

        $counterpart = $channel->isDirect()
            ? $channel->members->first(fn (User $member): bool => $member->id !== $viewer->id)
            : null;

        return new self(
            // The viewer rides along so a root that is an anonymous poll still
            // carries their own selection, which its hidden roster cannot convey.
            root: MessageData::fromMessage($message, $viewer->id),
            channelName: $channel->displayNameFor($viewer),
            channelSlug: $channel->slug,
            isDirectMessage: $channel->isDirectMessage(),
            dmParticipant: $counterpart instanceof User ? MentionData::fromUser($counterpart) : null,
        );
    }
}
