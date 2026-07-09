<?php

namespace App\Actions\Channels;

use App\Models\Channel;
use App\Models\User;

class MarkChannelRead
{
    /**
     * Advance the user's read pointer to the channel's most recent message.
     *
     * Pointing at the latest message id (soft-deleted rows included, so the
     * pointer never lags behind a deleted tail) zeroes the sidebar's unread and
     * mention badges. A channel with no messages leaves the pointer untouched,
     * and a non-member is a no-op because there is no pivot row to update.
     */
    public function handle(Channel $channel, User $user): void
    {
        $latestMessageId = $channel->messages()->withTrashed()->orderByDesc('id')->value('id');

        if ($latestMessageId === null) {
            return;
        }

        $user->channels()->updateExistingPivot($channel->id, [
            'last_read_message_id' => $latestMessageId,
        ]);
    }
}
