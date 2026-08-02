<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Enums\PresenceState;
use App\Events\UserPresenceChanged;
use App\Models\User;

/**
 * Set — or clear — a user's manual away override.
 *
 * Away here is an override that outlives the browser: it is stored on the row,
 * so it survives a reconnect, a new device, and a restart until the user unsets
 * it. Clearing it hands the answer back to the live connections, which is why
 * the broadcast carries the *effective* state rather than the one that was just
 * written — a user who unsets away from a tab that has since gone idle stays
 * away, and teammates are told so.
 *
 * @return PresenceState what teammates now see, which is not always what was written
 */
class SetPresenceOverride
{
    public function handle(User $user, PresenceState $state): PresenceState
    {
        $user->forceFill(['presence_state' => $state])->save();

        $effective = $user->availability()->presence();

        event(new UserPresenceChanged($user, $effective));

        return $effective;
    }
}
