<?php

namespace App\Actions\Channels;

use App\Data\MessageData;
use App\Events\MessageDeleted;
use App\Models\Channel;
use App\Models\Message;

class DeleteMessage
{
    /**
     * Soft-delete a message and broadcast the tombstone.
     *
     * The row is kept so the client can render a "message deleted" placeholder
     * in place. The broadcast reuses {@see MessageData}, which blanks the body of
     * a trashed message, so no deleted content ever reaches other subscribers.
     */
    public function handle(Channel $channel, Message $message): void
    {
        $message->delete();

        $message->loadMissing('user');
        MessageDeleted::dispatch($channel, MessageData::fromMessage($message));
    }
}
