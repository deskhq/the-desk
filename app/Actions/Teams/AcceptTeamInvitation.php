<?php

declare(strict_types=1);

namespace App\Actions\Teams;

use App\Enums\AuditAction;
use App\Events\AuditableActionOccurred;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Turns a pending invitation into a workspace membership.
 *
 * The membership, the stamped invitation and the team switch commit together,
 * so an accepted invitation can never exist without the membership it granted.
 * The person accepting is the actor: this is the one workspace fact its own
 * subject causes.
 */
class AcceptTeamInvitation
{
    public function handle(TeamInvitation $invitation, User $user): void
    {
        $team = $invitation->team;

        DB::transaction(function () use ($user, $invitation, $team): void {
            $team->memberships()->firstOrCreate(
                ['user_id' => $user->id],
                ['role' => $invitation->role],
            );

            $invitation->update(['accepted_at' => now()]);

            $user->switchTeam($team);
        });

        event(new AuditableActionOccurred($team, $user, AuditAction::InvitationAccepted, $invitation, [
            'email' => $invitation->email,
        ]));
    }
}
