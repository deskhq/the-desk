<?php

declare(strict_types=1);

namespace App\Actions\Channels;

use App\Data\PollData;
use App\Events\PollVoteChanged;
use App\Models\Channel;
use App\Models\Poll;

class ClosePoll
{
    /**
     * Close a poll, freezing its tally, and broadcast the frozen state.
     *
     * The close is claimed atomically with a conditional `closed_at is null`
     * update, so it is idempotent and race-free: a repeat (or a concurrent second)
     * request updates zero rows and returns without moving the timestamp or
     * re-broadcasting. The close rides the same {@see PollVoteChanged} event as a
     * vote (the payload carries `closedAt`), so every open timeline flips to the
     * results-revealed state live.
     */
    public function handle(Channel $channel, Poll $poll): void
    {
        $now = now();

        $closed = Poll::query()
            ->whereKey($poll->id)
            ->whereNull('closed_at')
            ->update(['closed_at' => $now]);

        if ($closed === 0) {
            return;
        }

        $poll->setAttribute('closed_at', $now);
        $poll->load('options.votes.user');

        event(new PollVoteChanged($channel, $poll->message_id, PollData::fromPoll($poll)));
    }
}
