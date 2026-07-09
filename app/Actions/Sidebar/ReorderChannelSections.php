<?php

namespace App\Actions\Sidebar;

use App\Models\Team;
use App\Models\User;

class ReorderChannelSections
{
    /**
     * Persist the user's manual order of their custom sections in the team.
     *
     * Each id is assigned its index as the new position, so the sidebar renders
     * the sections in exactly the order given. Scoped to the user's own sections
     * in the team, so ids for other users or teams are ignored.
     *
     * @param  list<string>  $orderedIds
     */
    public function handle(User $user, Team $team, array $orderedIds): void
    {
        $sections = $user->channelSections()
            ->where('team_id', $team->id)
            ->pluck('id')
            ->all();

        foreach ($orderedIds as $index => $id) {
            if (in_array($id, $sections, true)) {
                $user->channelSections()->whereKey($id)->update(['position' => $index]);
            }
        }
    }
}
