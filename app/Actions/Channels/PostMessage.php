<?php

namespace App\Actions\Channels;

use App\Data\MessageData;
use App\Events\MessageSent;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;

class PostMessage
{
    public function __construct(private SyncMentions $syncMentions) {}

    /**
     * Post a message to a channel on behalf of a user.
     *
     * Keyed on the client-generated uuid so a resent optimistic message resolves
     * to the row that already exists instead of creating a duplicate. Only a
     * genuinely new message parses its mentions and broadcasts, keeping the retry
     * path side-effect free.
     *
     * When `$replyToId` is set the message renders as an inline quote of that
     * parent; the request layer has already checked it points at a live message
     * in the same channel.
     */
    public function handle(Channel $channel, User $author, string $body, string $clientUuid, ?string $replyToId = null): Message
    {
        $message = $channel->messages()->firstOrCreate(
            ['client_uuid' => $clientUuid],
            ['user_id' => $author->id, 'body' => $body, 'reply_to_id' => $replyToId],
        );

        if ($message->wasRecentlyCreated) {
            $this->syncMentions->handle($channel, $message);
            $message->setRelation('user', $author);
            $message->load(['mentionedUsers', 'replyTo.user', 'replyTo.mentionedUsers']);
            MessageSent::dispatch($channel, MessageData::fromMessage($message));
        }

        return $message;
    }
}
