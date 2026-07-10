<?php

namespace App\Actions\Teams;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TransferTeamOwnership
{
    /**
     * Transfer ownership of the team from the current owner to a new owner.
     *
     * The demotion and promotion run in a single transaction so the single-owner
     * invariant is never observable as broken: there is no committed window with
     * zero or two owners.
     */
    public function handle(Team $team, User $currentOwner, User $newOwner): void
    {
        DB::transaction(function () use ($team, $currentOwner, $newOwner) {
            $team->memberships()
                ->where('user_id', $currentOwner->id)
                ->update(['role' => TeamRole::Admin]);

            $team->memberships()
                ->where('user_id', $newOwner->id)
                ->update(['role' => TeamRole::Owner]);
        });
    }
}
