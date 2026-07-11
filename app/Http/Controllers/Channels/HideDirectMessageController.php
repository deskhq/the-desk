<?php

namespace App\Http\Controllers\Channels;

use App\Actions\Channels\HideDirectMessage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Channels\HideDirectMessageRequest;
use App\Models\Channel;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;

class HideDirectMessageController extends Controller
{
    /**
     * Close (hide) the direct message from the current user's sidebar.
     *
     * Redirects back and lets Inertia recompute the shared `channels` prop so the
     * DM leaves the sidebar without a full reload; a later message re-surfaces it.
     */
    public function store(HideDirectMessageRequest $request, Team $team, Channel $channel, HideDirectMessage $hideDirectMessage): RedirectResponse
    {
        $hideDirectMessage->handle($channel, $request->user());

        return back();
    }
}
