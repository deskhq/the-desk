<?php

declare(strict_types=1);

namespace App\Actions\Teams;

use App\Enums\AuditAction;
use App\Events\AuditableActionOccurred;
use App\Models\Team;
use App\Models\User;

/**
 * Removes a member from a workspace, along with everything their membership
 * carried.
 *
 * Their user groups go with the membership, and a member parked on this
 * workspace is moved to their personal team so they never land on one they no
 * longer belong to.
 */
class RemoveTeamMember
{
    public function handle(Team $team, User $member, User $actor): void
    {
        $team->memberships()
            ->where('user_id', $member->id)
            ->delete();

        $member->leaveUserGroups($team);

        if ($member->isCurrentTeam($team)) {
            $member->switchTeam($member->personalTeam());
        }

        event(new AuditableActionOccurred($team, $actor, AuditAction::MemberRemoved, $member, [
            'member_name' => $member->name,
        ]));
    }
}
