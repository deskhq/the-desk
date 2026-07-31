<?php

namespace App\Actions\Channels;

use App\Data\UserData;
use App\Events\MessageRead;
use App\Events\ReadStateAdvanced;
use App\Models\Channel;
use App\Models\ChannelMember;
use App\Models\User;
use App\Support\ChannelMembership;

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
     * A real advance broadcasts two independent signals. {@see ReadStateAdvanced}
     * always goes to the reader's own other devices so their sidebar badge
     * clears there too, skipping the device that just read (it already gets
     * fresh counts in this request's response). {@see MessageRead} goes to peers
     * so they can update their "Seen by" affordance, and only for a user who
     * shares read receipts — that preference governs what peers see, never
     * whether a user's own devices stay in step. The advance guard keeps both
     * silent on the frequent no-op calls the client fires on every incoming
     * message and window focus.
     */
    public function handle(Channel $channel, User $user): void
    {
        $latestMessageId = $channel->messages()->withTrashed()->orderByDesc('id')->value('id');

        if ($latestMessageId === null) {
            return;
        }

        $member = new ChannelMembership($channel, $user)->row();

        if (! $member instanceof ChannelMember || $member->last_read_message_id === $latestMessageId) {
            return;
        }

        $member->update(['last_read_message_id' => $latestMessageId]);

        broadcast(new ReadStateAdvanced($user->id, $channel->id))->toOthers();

        if ($user->share_read_receipts) {
            event(new MessageRead($channel, UserData::fromUser($user), $latestMessageId));
        }
    }
}
