<?php

declare(strict_types=1);

namespace App\Actions\Channels;

use App\Events\MessagePinned;
use App\Models\Channel;
use App\Models\Message;

class UnpinMessage
{
    /**
     * Unpin a message from its channel, then broadcast the fresh patch.
     *
     * Idempotent: hard-deleting the pin row returns how many rows went, so an
     * already-unpinned message is a no-op that broadcasts nothing. When a pin was
     * actually removed the in-memory relation is cleared and the slim
     * {@see MessagePinned} patch broadcasts so every open timeline drops the
     * indicator and the masthead count ticks down live. Also the removal path used
     * when a pinned message is soft-deleted — a no-op when the message had no pin.
     */
    public function handle(Channel $channel, Message $message): void
    {
        $removed = $message->pin()->delete();

        if ($removed === 0) {
            return;
        }

        $message->setRelation('pin', null);

        event(new MessagePinned(
            channel: $channel,
            messageId: $message->id,
            pinned: false,
            pinnedBy: null,
            pinCount: $channel->pins()->count(),
        ));
    }
}
