<?php

declare(strict_types=1);

namespace App\Actions\Channels;

use App\Data\MentionData;
use App\Events\MessagePinned;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;

class PinMessage
{
    /**
     * Pin a message to its channel, then broadcast the fresh patch.
     *
     * Idempotent: the unique `message_id` means a second pin finds the existing
     * row and returns without a duplicate — an already-pinned message is a no-op
     * that broadcasts nothing. A genuinely new pin reloads the `pinnedBy` user for
     * the attribution and broadcasts the slim {@see MessagePinned} patch so every
     * open timeline shows the indicator and the masthead count ticks up live.
     */
    public function handle(Channel $channel, Message $message, User $user): void
    {
        $pin = $message->pin()->firstOrCreate([], [
            'channel_id' => $channel->id,
            'pinned_by' => $user->id,
        ]);

        if (! $pin->wasRecentlyCreated) {
            return;
        }

        event(new MessagePinned(
            channel: $channel,
            messageId: $message->id,
            pinned: true,
            pinnedBy: MentionData::fromUser($user),
            pinCount: $channel->pins()->count(),
        ));
    }
}
