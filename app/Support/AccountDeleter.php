<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AccountDeleter
{
    /**
     * Permanently delete a user's account, enforcing the content and ownership
     * policy rather than relying on the raw foreign-key cascades.
     *
     * Authored messages are reassigned to the retained "Deleted User" tombstone
     * so channel history stays coherent (anonymize, not remove). The user's
     * personal teams are torn down with them, while their memberships of shared
     * teams simply drop via the cascade — the sole-owner-of-a-shared-team case is
     * blocked upstream in {@see ProfileDeleteRequest}.
     */
    public function delete(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $tombstone = User::tombstone();

            Message::withTrashed()
                ->where('user_id', $user->id)
                ->update(['user_id' => $tombstone->id]);

            $user->teams()
                ->where('is_personal', true)
                ->get()
                ->each(fn (Team $team) => $team->delete());

            $user->delete();
        });
    }
}
