<?php

declare(strict_types=1);

namespace App\Http\Controllers\Channels;

use App\Http\Controllers\Controller;
use App\Http\Requests\Channels\HideDirectMessageRequest;
use App\Models\Channel;
use App\Models\Team;
use App\Support\ChannelMembership;
use Illuminate\Http\RedirectResponse;

final class HideDirectMessageController extends Controller
{
    /**
     * Close (hide) the direct message from the current user's sidebar.
     *
     * When the user closes the DM they are currently viewing (`leaving`), redirect
     * them home so they don't sit on the now-hidden conversation; otherwise
     * redirect back and let Inertia recompute the shared `channels` prop so the DM
     * leaves the sidebar without a full reload. A later message re-surfaces it.
     */
    public function store(HideDirectMessageRequest $request, Team $team, Channel $channel): RedirectResponse
    {
        new ChannelMembership($channel, $this->viewer($request))->hide();

        if ($request->boolean('leaving')) {
            return to_route('channels.index', ['team' => $team->slug]);
        }

        return back();
    }
}
