<?php

namespace App\Actions\Channels;

use App\Enums\NotificationLevel;
use App\Models\Channel;
use App\Models\User;

class UpdateChannelPreference
{
    /**
     * Persist the user's notification preferences for the channel.
     *
     * Writes only the member's own pivot row; a non-member is a no-op because
     * there is no membership to update.
     */
    public function handle(Channel $channel, User $user, bool $muted, NotificationLevel $notificationLevel): void
    {
        $user->channels()->updateExistingPivot($channel->id, [
            'muted' => $muted,
            'notification_level' => $notificationLevel->value,
        ]);
    }
}
