<?php

namespace App\Http\Controllers\Channels;

use App\Http\Controllers\Controller;
use App\Http\Requests\Channels\SaveChannelDraftRequest;
use App\Models\Channel;
use App\Models\Team;
use App\Support\ChannelMembership;
use Illuminate\Http\RedirectResponse;

class ChannelDraftController extends Controller
{
    /**
     * Save (or clear) the current user's unsent composer text for the channel.
     *
     * Redirects back and lets Inertia recompute the shared `channels` prop so the
     * sidebar's draft cue reflects the change without a full reload.
     */
    public function update(SaveChannelDraftRequest $request, Team $team, Channel $channel): RedirectResponse
    {
        new ChannelMembership($channel, $request->user())->saveDraft($request->validated('body'));

        return back();
    }
}
