<?php

declare(strict_types=1);

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
        // A soft delete leaves the row (and its FK-cascading children) in place,
        // so drop the reactions explicitly — a tombstone shows none, and they
        // would otherwise linger unreachable behind the deleted message.
        $message->reactions()->delete();

        $message->delete();

        $message->loadMissing('user');
        event(new MessageDeleted($channel, MessageData::fromMessage($message)));
    }
}
