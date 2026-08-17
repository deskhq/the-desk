<?php

declare(strict_types=1);

namespace App\Actions\Teams;

use App\Enums\AuditAction;
use App\Events\AuditableActionOccurred;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\Teams\TeamInvitation as TeamInvitationNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Sends a pending invitation again, on a fresh expiry window.
 *
 * The mail is only useful if the link it carries still works, so refreshing the
 * expiry is part of resending rather than a separate step the caller has to
 * remember. Throttling belongs to the surface, not here.
 */
final class ResendTeamInvitation
{
    public function handle(TeamInvitation $invitation, User $actor): void
    {
        $invitation->update(['expires_at' => now()->addDays(CreateTeamInvitation::EXPIRY_DAYS)]);

        event(new AuditableActionOccurred($invitation->team, $actor, AuditAction::InvitationResent, $invitation, [
            'email' => $invitation->email,
        ]));

        Notification::route('mail', $invitation->email)
            ->notify(new TeamInvitationNotification($invitation));
    }
}
