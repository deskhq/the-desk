<?php

namespace App\Http\Controllers\Channels;

use App\Actions\Channels\CastVote;
use App\Actions\Channels\ClosePoll;
use App\Actions\Channels\CreatePoll;
use App\Http\Controllers\Controller;
use App\Http\Requests\Channels\CastVoteRequest;
use App\Http\Requests\Channels\StorePollRequest;
use App\Models\Channel;
use App\Models\Poll;
use App\Models\PollOption;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class PollController extends Controller
{
    /**
     * Post a poll composed in the builder to the channel.
     *
     * The poll message arrives in every open timeline over the MessageSent
     * broadcast (it carries the poll payload), so redirecting back keeps the
     * author in the channel without a full reload.
     */
    public function store(StorePollRequest $request, Team $team, Channel $channel, CreatePoll $createPoll): RedirectResponse
    {
        $createPoll->handle(
            channel: $channel,
            author: $request->user(),
            question: $request->validated('question'),
            optionLabels: $request->validated('options'),
            allowMultiple: $request->boolean('allow_multiple'),
            isAnonymous: $request->boolean('is_anonymous'),
            clientUuid: $request->validated('client_uuid'),
            threadRootId: $request->validated('thread_root_id'),
            sentToChannel: $request->boolean('sent_to_channel'),
        );

        return back();
    }

    /**
     * Toggle the current user's vote for one of the poll's options.
     *
     * Votes are only accepted while the poll is open; a closed poll's tally is
     * frozen. The updated tally arrives over the PollVoteChanged broadcast.
     */
    public function vote(CastVoteRequest $request, Team $team, Channel $channel, Poll $poll, CastVote $castVote): RedirectResponse
    {
        abort_unless($poll->isOpen(), 403);

        $option = PollOption::query()
            ->where('poll_id', $poll->id)
            ->where('id', $request->validated('option_id'))
            ->firstOrFail();

        $castVote->handle($channel, $poll, $option, $request->user());

        return back();
    }

    /**
     * Close the poll, freezing its tally.
     *
     * Restricted to the poll's creator and team admins. The frozen state arrives
     * over the PollVoteChanged broadcast.
     */
    public function close(Team $team, Channel $channel, Poll $poll, ClosePoll $closePoll): RedirectResponse
    {
        Gate::authorize('close', $poll);

        $closePoll->handle($channel, $poll);

        return back();
    }
}
