<?php

namespace App\Http\Controllers\Channels;

use App\Actions\Channels\JoinChannel;
use App\Actions\Channels\RemoveChannelMember;
use App\Http\Controllers\Controller;
use App\Http\Requests\Channels\AddChannelMemberRequest;
use App\Http\Requests\Channels\RemoveChannelMemberRequest;
use App\Models\Channel;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ChannelMemberController extends Controller
{
    /**
     * Add a team member to a private channel.
     */
    public function store(AddChannelMemberRequest $request, Team $team, Channel $channel, JoinChannel $joinChannel): RedirectResponse
    {
        $user = User::findOrFail($request->validated('user_id'));

        $joinChannel->handle($channel, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member added.')]);

        return to_route('channels.show', ['team' => $team->slug, 'channel' => $channel->slug]);
    }

    /**
     * Remove a member from a private channel.
     */
    public function destroy(RemoveChannelMemberRequest $request, Team $team, Channel $channel, RemoveChannelMember $removeChannelMember): RedirectResponse
    {
        $user = User::findOrFail($request->validated('user_id'));

        $removeChannelMember->handle($channel, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member removed.')]);

        return to_route('channels.show', ['team' => $team->slug, 'channel' => $channel->slug]);
    }
}
