<?php

declare(strict_types=1);

namespace App\Actions\Teams;

use App\Enums\SecurityEventType;
use App\Events\SecurityEventOccurred;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a workspace and everything that only makes sense inside it.
 *
 * Members parked on the deleted workspace are moved to their personal team
 * first, so nobody is left pointing at a workspace that no longer exists; the
 * deleter is left alone, since the caller decides where they land. Destroying a
 * workspace is a security-relevant account action, so it is recorded here.
 */
final class DeleteTeam
{
    public function handle(Team $team, User $actor): void
    {
        DB::transaction(function () use ($team, $actor): void {
            User::where('current_team_id', $team->id)
                ->where('id', '!=', $actor->id)
                ->each(fn (User $affectedUser): bool => $affectedUser->switchTeam($affectedUser->personalTeamOrFail()));

            $team->invitations()->delete();
            $team->memberships()->delete();
            $team->delete();
        });

        event(new SecurityEventOccurred($actor, SecurityEventType::TeamDeleted));
    }
}
