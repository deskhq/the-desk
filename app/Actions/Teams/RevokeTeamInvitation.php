<?php

declare(strict_types=1);

namespace App\Actions\Teams;

use App\Enums\AuditAction;
use App\Events\AuditableActionOccurred;
use App\Models\TeamInvitation;
use App\Models\User;

/**
 * Cancels a pending invitation, so its link stops working.
 *
 * The audit entry is raised before the row goes, since it is recorded against
 * the invitation as its subject.
 */
final class RevokeTeamInvitation
{
    public function handle(TeamInvitation $invitation, User $actor): void
    {
        event(new AuditableActionOccurred($invitation->team, $actor, AuditAction::InvitationRevoked, $invitation, [
            'email' => $invitation->email,
        ]));

        $invitation->delete();
    }
}
