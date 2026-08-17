<?php

declare(strict_types=1);

namespace App\Actions\Sidebar;

use App\Models\Team;
use App\Models\User;
use App\Support\ManualOrder;

final class ReorderChannelSections
{
    /**
     * Persist the user's manual order of their custom sections in the team.
     *
     * Each id is assigned its index as the new position, so the sidebar renders
     * the sections in exactly the order given. Scoped to the user's own sections
     * in the team, so ids for other users or teams are ignored — the same walk a
     * channel drag runs, see {@see ManualOrder}.
     *
     * @param  list<string>  $orderedIds
     */
    public function handle(User $user, Team $team, array $orderedIds): void
    {
        ManualOrder::apply(
            $user->channelSections()->where('team_id', $team->id)->getQuery(),
            'id',
            $orderedIds,
        );
    }
}
