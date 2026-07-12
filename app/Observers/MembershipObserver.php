<?php

namespace App\Observers;

use App\Actions\Channels\CreateChannel;
use App\Actions\Channels\JoinChannel;
use App\Enums\ChannelVisibility;
use App\Models\Channel;
use App\Models\Membership;

class MembershipObserver
{
    public function __construct(
        private readonly CreateChannel $createChannel,
        private readonly JoinChannel $joinChannel,
    ) {}

    /**
     * Handle the Membership "created" event.
     *
     * Enforces the invariant that channel membership follows team membership:
     * whenever a user joins a team, ensure the team's protected #general channel
     * exists (creating it — and joining its creator — on the first membership)
     * and join the user to it.
     */
    public function created(Membership $membership): void
    {
        $general = Channel::where('team_id', $membership->team_id)
            ->where('slug', Channel::GENERAL_SLUG)
            ->first();

        if ($general === null) {
            $this->createChannel->handle(
                $membership->team,
                Channel::GENERAL_SLUG,
                ChannelVisibility::Public,
                $membership->user,
            );

            return;
        }

        $this->joinChannel->handle($general, $membership->user);
    }
}
