<?php

declare(strict_types=1);

namespace App\Actions\Teams;

use App\Enums\AuditAction;
use App\Enums\TeamRole;
use App\Events\AuditableActionOccurred;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\Teams\TeamInvitation as TeamInvitationNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Invites someone to a workspace by email.
 *
 * The invitation, the mail that carries it and the audit entry are one act, so
 * they live together: a surface that invites someone can never send the mail
 * without recording who opened the door.
 */
final class CreateTeamInvitation
{
    /**
     * The window an invitation stays valid for.
     */
    public const int EXPIRY_DAYS = 3;

    public function handle(Team $team, User $actor, string $email, TeamRole $role): TeamInvitation
    {
        /** @var TeamInvitation $invitation */
        $invitation = $team->invitations()->create([
            'email' => $email,
            'role' => $role,
            'invited_by' => $actor->id,
            'expires_at' => now()->addDays(self::EXPIRY_DAYS),
        ]);

        event(new AuditableActionOccurred($team, $actor, AuditAction::InvitationCreated, $invitation, [
            'email' => $invitation->email,
            'role' => $role->label(),
        ]));

        Notification::route('mail', $invitation->email)
            ->notify(new TeamInvitationNotification($invitation));

        return $invitation;
    }
}
