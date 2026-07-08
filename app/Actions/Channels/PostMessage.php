<?php

namespace App\Actions\Channels;

use App\Data\MessageData;
use App\Events\MessageSent;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;

class PostMessage
{
    /**
     * Post a message to a channel on behalf of a user.
     *
     * Keyed on the client-generated uuid so a resent optimistic message resolves
     * to the row that already exists instead of creating a duplicate. Only a
     * genuinely new message broadcasts, keeping the retry path side-effect free.
     */
    public function handle(Channel $channel, User $author, string $body, string $clientUuid): Message
    {
        $message = $channel->messages()->firstOrCreate(
            ['client_uuid' => $clientUuid],
            ['user_id' => $author->id, 'body' => $body],
        );

        if ($message->wasRecentlyCreated) {
            $message->setRelation('user', $author);
            MessageSent::dispatch($channel, MessageData::fromMessage($message));
        }

        return $message;
    }
}
