<?php

declare(strict_types=1);

namespace App\Http\Controllers\Channels;

use App\Actions\Channels\MarkAllThreadsRead;
use App\Enums\NavDestination;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class ThreadsController extends Controller
{
    /**
     * Send the legacy full-width Threads inbox onto the pinned destination.
     *
     * The inbox is a dock panel now (`?nav=threads` on a workspace route), so the
     * old URL survives only as a redirect — shared links and bookmarks still land
     * on the threads the user came for. The hop goes through the bare team URL,
     * which already forwards its query string on to #general.
     */
    public function index(Team $team): RedirectResponse
    {
        return to_route('channels.index', [
            'team' => $team->slug,
            NavDestination::QUERY_PARAM => NavDestination::Threads->value,
        ]);
    }

    /**
     * Clear the viewer's Threads inbox for this team: every followed thread holding
     * unread replies is marked read in one write.
     *
     * Redirects back so the panel's own reload picks the emptied inbox, the cleared
     * "Unread" tally and the dropped rail dot out of fresh props.
     */
    public function markAllRead(Request $request, Team $team, MarkAllThreadsRead $markAllThreadsRead): RedirectResponse
    {
        $markAllThreadsRead->handle($this->viewer($request), $team);

        return back();
    }
}
