<?php

namespace App\Http\Controllers\Channels;

use App\Actions\Channels\OpenDirectMessage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Channels\OpenDirectMessageRequest;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class DirectMessageController extends Controller
{
    /**
     * Open (find-or-create) the 1:1 direct message with the target user and
     * redirect into it. Deduped by the participant `dm_key`, so repeated opens
     * from either side land in the same channel.
     */
    public function store(OpenDirectMessageRequest $request, Team $team, OpenDirectMessage $openDirectMessage): RedirectResponse
    {
        $target = User::whereKey($request->validated('user_id'))->firstOrFail();

        $channel = $openDirectMessage->handle($team, $request->user(), $target);

        return to_route('channels.show', ['team' => $team->slug, 'channel' => $channel->slug]);
    }
}
