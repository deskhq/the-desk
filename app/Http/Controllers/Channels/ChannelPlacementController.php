<?php

declare(strict_types=1);

namespace App\Http\Controllers\Channels;

use App\Http\Controllers\Controller;
use App\Http\Requests\Channels\UpdateChannelPlacementRequest;
use App\Models\Channel;
use App\Models\Team;
use App\Support\ChannelMembership;
use Illuminate\Http\RedirectResponse;

final class ChannelPlacementController extends Controller
{
    /**
     * Place the channel within the sidebar for the current user: file it under a
     * section and/or reorder the group it now lives in.
     *
     * Redirects back and lets Inertia recompute the shared `channels` prop so the
     * sidebar re-partitions without a full reload.
     */
    public function update(UpdateChannelPlacementRequest $request, Team $team, Channel $channel): RedirectResponse
    {
        new ChannelMembership($channel, $this->viewer($request))->place(
            orderedIds: $request->orderedIds(),
            moveSection: $request->movesSection(),
            sectionId: $request->input('section_id'),
        );

        return back();
    }
}
