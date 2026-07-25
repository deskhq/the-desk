<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\URL;

/**
 * Where a signed-in user belongs: their current team's channels workspace.
 *
 * Every way into the app resolves its destination here, so no sign-in path can
 * quietly fall back to Fortify's `/` default and drop someone on the public
 * marketing page instead of their workspace.
 */
class WorkspaceRedirect
{
    /**
     * The path of the user's channels workspace, or null when they have no team
     * to land in.
     *
     * Registering the team as the default `current_team` route parameter is part
     * of resolving the destination rather than an afterthought: the workspace
     * page that renders next generates its Wayfinder URLs from that default.
     */
    public static function pathFor(?User $user): ?string
    {
        $team = $user instanceof User ? $user->currentTeam ?? $user->personalTeam() : null;

        if (! $team instanceof Team) {
            return null;
        }

        URL::defaults(['current_team' => $team->slug]);

        return route('channels.index', ['team' => $team->slug], absolute: false);
    }
}
