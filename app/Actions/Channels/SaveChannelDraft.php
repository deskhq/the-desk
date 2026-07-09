<?php

namespace App\Actions\Channels;

use App\Models\Channel;
use App\Models\User;

class SaveChannelDraft
{
    /**
     * Persist the user's unsent composer text for the channel.
     *
     * Writes only the member's own pivot row; a non-member is a no-op because
     * there is no membership to update. A blank draft (empty or whitespace-only)
     * is stored as null so it clears the pending-draft cue rather than lingering
     * as an "empty" draft.
     */
    public function handle(Channel $channel, User $user, ?string $draft): void
    {
        $user->channels()->updateExistingPivot($channel->id, [
            'draft' => $draft !== null && trim($draft) !== '' ? $draft : null,
        ]);
    }
}
