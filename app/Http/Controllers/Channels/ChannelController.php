<?php

namespace App\Http\Controllers\Channels;

use App\Data\ChannelData;
use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ChannelController extends Controller
{
    /**
     * Redirect a bare team URL to the team's #general channel.
     */
    public function index(Team $team): RedirectResponse
    {
        return to_route('channels.show', [
            'team' => $team->slug,
            'channel' => Channel::GENERAL_SLUG,
        ]);
    }

    /**
     * Show a channel with the current user's channel sidebar.
     */
    public function show(Request $request, Team $team, Channel $channel): Response
    {
        Gate::authorize('view', $channel);

        $channels = $request->user()->channels()
            ->where('channels.team_id', $team->id)
            ->orderBy('name')
            ->get();

        return Inertia::render('channels/Show', [
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'slug' => $team->slug,
            ],
            'channel' => ChannelData::fromChannel($channel),
            'channels' => ChannelData::collect($channels),
        ]);
    }
}
