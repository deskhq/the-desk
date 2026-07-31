<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\TeamInvitation;
use App\Models\User;

/**
 * The workspace invitations still open to a user: unaccepted, unexpired, and
 * addressed to their email.
 *
 * Matched on a lower-cased email rather than on a foreign key, because an
 * invitation is written before its recipient necessarily has an account and
 * addresses are case-insensitive in practice. That is the whole reason this is
 * not simply a relation on {@see User}.
 *
 * Unlike its siblings this is not workspace-scoped: an invitation's whole point
 * is to name a workspace the viewer is not in yet.
 */
final class PendingInvitations
{
    /**
     * The user's open invitations, newest first, shaped for the accept prompt.
     *
     * @return array<int, array{code: string, inviterName: string, team: array{name: string, slug: string}}>
     */
    public static function forUser(User $user): array
    {
        return TeamInvitation::query()
            ->with(['inviter', 'team'])
            ->whereRaw('LOWER(email) = ?', [strtolower($user->email)])
            ->whereNull('accepted_at')
            ->where(fn ($query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>=', now()))
            ->latest()
            ->get()
            ->map(fn (TeamInvitation $invitation): array => [
                'code' => $invitation->code,
                'inviterName' => $invitation->inviter->name,
                'team' => [
                    'name' => $invitation->team->name,
                    'slug' => $invitation->team->slug,
                ],
            ])
            ->all();
    }
}
