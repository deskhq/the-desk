<?php

declare(strict_types=1);

namespace App\Actions\Channels;

use App\Models\Channel;
use App\Models\User;

class SetChannelStar
{
    /**
     * Set the user's star (favorite) flag for the channel.
     *
     * Writes only the member's own pivot row; a non-member is a no-op because
     * there is no membership to update.
     */
    public function handle(Channel $channel, User $user, bool $starred): void
    {
        $user->channels()->updateExistingPivot($channel->id, [
            'starred' => $starred,
        ]);
    }
}
