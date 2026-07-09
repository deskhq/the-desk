<?php

namespace App\Actions\Sidebar;

use App\Models\ChannelSection;
use App\Models\Team;
use App\Models\User;

class CreateChannelSection
{
    /**
     * Create a custom sidebar section for the user in the team.
     *
     * The new section is appended after the user's existing sections in the team,
     * so a freshly created section lands at the bottom of the custom groups.
     */
    public function handle(User $user, Team $team, string $name): ChannelSection
    {
        $nextPosition = (int) $user->channelSections()
            ->where('team_id', $team->id)
            ->max('position');

        return $user->channelSections()->create([
            'team_id' => $team->id,
            'name' => $name,
            'position' => $nextPosition + 1,
            'collapsed' => false,
        ]);
    }
}
