<?php

namespace App\Actions\Channels;

use App\Models\Channel;
use App\Models\Team;
use App\Models\User;

class SetChannelPlacement
{
    /**
     * Place a channel within the sidebar: file it under a section and/or reorder
     * the group it now lives in.
     *
     * `$orderedIds` is the full, ordered list of channel ids in the target group;
     * each of the user's matching memberships takes its index as the new position,
     * so a drag persists the whole group's order in one write. When `$moveSection`
     * is true the dragged channel's `section_id` is set to `$sectionId` (null for
     * the default "Channels" group); a pure within-group reorder leaves the
     * assignment untouched.
     *
     * Only the user's own pivot rows in the team are written, so ids for channels
     * they don't belong to — or in another team — are ignored.
     *
     * @param  list<string>  $orderedIds
     */
    public function handle(User $user, Team $team, Channel $channel, array $orderedIds, bool $moveSection, ?string $sectionId): void
    {
        $memberChannelIds = $user->visibleChannelIds($team)->all();

        foreach ($orderedIds as $index => $id) {
            if (in_array($id, $memberChannelIds, true)) {
                $user->channels()->updateExistingPivot($id, ['position' => $index]);
            }
        }

        if ($moveSection) {
            $user->channels()->updateExistingPivot($channel->id, ['section_id' => $sectionId]);
        }
    }
}
