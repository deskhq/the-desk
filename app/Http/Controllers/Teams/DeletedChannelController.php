<?php

declare(strict_types=1);

namespace App\Http\Controllers\Teams;

use App\Actions\Channels\RestoreChannel;
use App\Data\DeletedChannelData;
use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class DeletedChannelController extends Controller
{
    /**
     * Show the workspace's recently-deleted channels — the only way back from a
     * channel deletion, for as long as the grace window is open.
     */
    public function index(Request $request, Team $team): Response
    {
        Gate::authorize('viewDeletedChannels', $team);

        return Inertia::render('teams/DeletedChannels', [
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'slug' => $team->slug,
            ],
            'channels' => DeletedChannelData::forTeam($team),
        ]);
    }

    /**
     * Restore a deleted channel, putting it and everything it holds back.
     *
     * The route binds the channel by id including trashed rows and scopes it to
     * the workspace, so a live channel or one from another workspace never
     * reaches here; the policy then requires the same Admin+ standing that the
     * deletion did. A slug taken in the meantime aborts the restore with a
     * validation error rather than moving the channel's URL.
     */
    public function restore(Request $request, Team $team, Channel $channel, RestoreChannel $restoreChannel): RedirectResponse
    {
        Gate::authorize('restore', $channel);

        $restoreChannel->handle($channel, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Restored #:channel', ['channel' => $channel->name])]);

        return to_route('teams.deleted-channels.index', ['team' => $team->slug]);
    }
}
