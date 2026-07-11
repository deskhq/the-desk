<?php

namespace App\Actions\Channels;

use App\Models\Channel;
use App\Models\User;

class HideDirectMessage
{
    /**
     * Close (hide) the direct message from the user's sidebar.
     *
     * Stamps the member's own pivot row with the current time; the sidebar's
     * listing predicate then drops the DM until a message arrives after this
     * instant. Writes only the caller's row, so each side hides independently.
     */
    public function handle(Channel $channel, User $user): void
    {
        $user->channels()->updateExistingPivot($channel->id, [
            'hidden_at' => now(),
        ]);
    }
}
