<?php

namespace App\Http\Controllers\Channels;

use App\Actions\Channels\ToggleReaction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Channels\ReactionRequest;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;

class ReactionController extends Controller
{
    /**
     * Toggle the current user's emoji reaction on a message.
     *
     * A single endpoint flips the reaction on or off; the action re-aggregates
     * and broadcasts the message's reactions so every viewer patches it live.
     * Redirecting back keeps the user in the channel — the pill update arrives
     * over the broadcast, not the response.
     */
    public function store(ReactionRequest $request, Team $team, Channel $channel, Message $message, ToggleReaction $toggleReaction): RedirectResponse
    {
        $toggleReaction->handle($channel, $message, $request->user(), $request->validated('emoji'));

        return back();
    }
}
