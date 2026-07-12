<?php

namespace App\Actions\Channels;

use App\Data\UserData;
use App\Events\MessageRead;
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
     *
     * When the pointer actually advances and the user shares read receipts, a
     * {@see MessageRead} event is broadcast so peers can update their "Seen by"
     * affordance. The advance guard also avoids re-broadcasting on the frequent
     * no-op calls the client fires on every incoming message and window focus.
     */
    public function handle(Channel $channel, User $user): void
    {
        $latestMessageId = $channel->messages()->withTrashed()->orderByDesc('id')->value('id');

        if ($latestMessageId === null) {
            return;
        }

        $member = $channel->channelMembers()->where('user_id', $user->id)->first();

        if ($member === null || $member->last_read_message_id === $latestMessageId) {
            return;
        }

        $member->update(['last_read_message_id' => $latestMessageId]);

        if ($user->share_read_receipts) {
            event(new MessageRead($channel, UserData::fromUser($user), $latestMessageId));
        }
    }
}
