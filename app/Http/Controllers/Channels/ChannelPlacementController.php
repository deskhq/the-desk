<?php

namespace App\Http\Controllers\Channels;

use App\Actions\Channels\SetChannelPlacement;
use App\Http\Controllers\Controller;
use App\Http\Requests\Channels\UpdateChannelPlacementRequest;
use App\Models\Channel;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;

class ChannelPlacementController extends Controller
{
    /**
     * Place the channel within the sidebar for the current user: file it under a
     * section and/or reorder the group it now lives in.
     *
     * Redirects back and lets Inertia recompute the shared `channels` prop so the
     * sidebar re-partitions without a full reload.
     */
    public function update(UpdateChannelPlacementRequest $request, Team $team, Channel $channel, SetChannelPlacement $setChannelPlacement): RedirectResponse
    {
        $setChannelPlacement->handle(
            user: $request->user(),
            team: $team,
            channel: $channel,
            orderedIds: $request->orderedIds(),
            moveSection: $request->movesSection(),
            sectionId: $request->input('section_id'),
        );

        return back();
    }
}
