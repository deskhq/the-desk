<?php

declare(strict_types=1);

namespace App\Actions\Channels;

use App\Data\PollData;
use App\Events\PollVoteChanged;
use App\Models\Channel;
use App\Models\Poll;
use App\Models\PollOption;
use App\Models\PollVote;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CastVote
{
    /**
     * Toggle a user's vote for a poll option and broadcast the fresh tally.
     *
     * Voting mirrors reactions: re-clicking the option you chose retracts it. On a
     * single-choice poll, choosing a different option swaps — the user's other
     * vote on this poll is cleared first, so at most one stands. A multiple-choice
     * poll toggles each option independently. The tally is then re-aggregated
     * without a viewer and broadcast, so every open timeline patches live.
     */
    public function handle(Channel $channel, Poll $poll, PollOption $option, User $user): void
    {
        DB::transaction(function () use ($poll, $option, $user): void {
            $existing = $option->votes()->where('user_id', $user->id)->first();

            if ($existing !== null) {
                $existing->delete();

                return;
            }

            if (! $poll->allow_multiple) {
                PollVote::query()
                    ->whereIn('poll_option_id', $poll->options->pluck('id'))
                    ->where('user_id', $user->id)
                    ->delete();
            }

            $option->votes()->create(['user_id' => $user->id]);
        });

        $poll->load('options.votes.user');

        event(new PollVoteChanged($channel, $poll->message_id, PollData::fromPoll($poll)));
    }
}
