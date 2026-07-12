<?php

use App\Models\Channel;
use App\Models\Team;
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

/**
 * Authorize a user's own private notification channel.
 *
 * Only the user themselves may subscribe to `user.{id}`, which delivers
 * personal signals such as a brand-new direct message appearing in their
 * sidebar. No other member of the team can listen in.
 */
Broadcast::channel('user.{userId}', fn (User $user, string $userId): bool => $user->id === $userId);

/**
 * Track which team members are currently online.
 *
 * Only members of the team may join, and the identity returned here becomes
 * their entry in the presence roster that drives the online dots on avatars.
 *
 * @return array{id: string, name: string}|null
 */
Broadcast::channel('team.{teamId}', function (User $user, string $teamId): ?array {
    $team = Team::find($teamId);

    if ($team === null || ! $team->members()->whereKey($user->id)->exists()) {
        return null;
    }

    return ['id' => $user->id, 'name' => $user->name];
});
