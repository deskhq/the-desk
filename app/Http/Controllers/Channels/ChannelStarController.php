<?php

namespace App\Http\Controllers\Channels;

use App\Http\Controllers\Controller;
use App\Http\Requests\Channels\UpdateChannelStarRequest;
use App\Models\Channel;
use App\Models\Team;
use App\Support\ChannelMembership;
use Illuminate\Http\RedirectResponse;

class ChannelStarController extends Controller
{
    /**
     * Star or unstar the channel for the current user.
     *
     * Redirects back and lets Inertia recompute the shared `channels` prop so the
     * sidebar's "Starred" section reflects the change without a full reload.
     */
    public function update(UpdateChannelStarRequest $request, Team $team, Channel $channel): RedirectResponse
    {
        new ChannelMembership($channel, $request->user())->star($request->boolean('starred'));

        return back();
    }
}
