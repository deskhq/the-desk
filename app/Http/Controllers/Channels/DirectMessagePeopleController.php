<?php

namespace App\Http\Controllers\Channels;

use App\Actions\Channels\OpenDirectMessage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Channels\AddDirectMessagePeopleRequest;
use App\Models\Channel;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class DirectMessagePeopleController extends Controller
{
    /**
     * Add people to a direct message, opening (or creating) the conversation for
     * the resulting member set and redirecting into it.
     *
     * The target set is the DM's current members plus the selected teammates, so
     * a 1:1 grows into a group and a group grows further. The open-or-create is
     * keyed on that set: an identical set reuses the existing conversation (its
     * history intact) rather than spawning a duplicate. The current members are
     * resolved before the redirect so an add that changes nothing still lands the
     * caller back in the same conversation.
     */
    public function store(AddDirectMessagePeopleRequest $request, Team $team, Channel $channel, OpenDirectMessage $openDirectMessage): RedirectResponse
    {
        $added = User::whereIn('id', $request->validated('user_ids'))->get();

        $participants = $channel->members->merge($added)->unique('id');

        $conversation = $openDirectMessage->openForUsers($team, $request->user(), $participants);

        return to_route('channels.show', ['team' => $team->slug, 'channel' => $conversation->slug]);
    }
}
