<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Models\Message;
use App\Models\MessagePin;
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
     * so channel history stays coherent (anonymize, not remove). Message pins are
     * reattributed the same way so a pin they created survives with a coherent
     * "Pinned by Deleted User" line rather than dangling or vanishing. The user's
     * personal teams are torn down with them, while their memberships of shared
     * teams simply drop via the cascade — the sole-owner-of-a-shared-team case is
     * blocked upstream in {@see ProfileDeleteRequest}.
     *
     * Their web-push subscriptions are dropped explicitly: the owning column is
     * a polymorphic reference, so no foreign key sweeps them, and a stale row
     * would keep this instance pushing to a device whose account is gone.
     */
    public function delete(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $tombstone = User::tombstone();

            $user->pushSubscriptions()->delete();

            Message::withTrashed()
                ->where('user_id', $user->id)
                ->update(['user_id' => $tombstone->id]);

            MessagePin::where('pinned_by', $user->id)
                ->update(['pinned_by' => $tombstone->id]);

            $user->teams()
                ->where('is_personal', true)
                ->get()
                ->each(fn (Team $team) => $team->delete());

            $user->delete();
        });
    }
}
