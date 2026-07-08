<?php

namespace App\Actions\Channels;

use App\Data\MessageData;
use App\Events\MessageUpdated;
use App\Models\Channel;
use App\Models\Message;

class EditMessage
{
    /**
     * Edit a message's body on behalf of its author.
     *
     * Stamps `edited_at` so the client can show the "(edited)" marker, then
     * broadcasts the new state so every subscriber reconciles the row in place.
     */
    public function handle(Channel $channel, Message $message, string $body): Message
    {
        $message->update([
            'body' => $body,
            'edited_at' => now(),
        ]);

        $message->loadMissing('user');
        MessageUpdated::dispatch($channel, MessageData::fromMessage($message));

        return $message;
    }
}
