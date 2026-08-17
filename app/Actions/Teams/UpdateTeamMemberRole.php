<?php

declare(strict_types=1);

namespace App\Actions\Teams;

use App\Enums\AuditAction;
use App\Enums\TeamRole;
use App\Events\AuditableActionOccurred;
use App\Models\Team;
use App\Models\User;

/**
 * Moves a member to a different role in the workspace.
 *
 * Re-submitting the role someone already holds is a no-op that records nothing:
 * a role change is only auditable when the role actually moved.
 */
final class UpdateTeamMemberRole
{
    public function handle(Team $team, User $member, TeamRole $role, User $actor): void
    {
        $membership = $team->memberships()
            ->where('user_id', $member->id)
            ->firstOrFail();

        $oldRole = $membership->role;

        $membership->update(['role' => $role]);

        if ($role === $oldRole) {
            return;
        }

        event(new AuditableActionOccurred($team, $actor, AuditAction::MemberRoleChanged, $member, [
            'member_name' => $member->name,
            'old_role' => $oldRole->label(),
            'new_role' => $role->label(),
        ]));
    }
}
