<?php

use App\Models\Channel;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/**
 * Authorize realtime message delivery for a channel.
 *
 * Only members of the channel may subscribe, matching who is allowed to post.
 */
Broadcast::channel('channel.{channelId}', function (User $user, string $channelId): bool {
    $channel = Channel::find($channelId);

    return $channel !== null
        && $channel->members()->whereKey($user->id)->exists();
});
