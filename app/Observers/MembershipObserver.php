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
     * and join the user to it, along with every other channel the workspace has
     * marked as a default.
     *
     * This is the one place a workspace membership is born — invite acceptance,
     * directory provisioning and workspace creation all write the pivot through
     * the model — so it is also the one place the default-channel set has to be
     * applied.
     */
    public function created(Membership $membership): void
    {
        $team = $membership->team;

        $general = Channel::where('team_id', $membership->team_id)
            ->where('slug', Channel::GENERAL_SLUG)
            ->first();

        if ($general === null) {
            $this->createChannel->handle(
                $team,
                Channel::GENERAL_SLUG,
                ChannelVisibility::Public,
                $membership->user,
            );

            return;
        }

        // Team onboarding, not a channel join: joining the workspace's default
        // channels is structural, so it posts no "member joined" notice (which
        // would badge every default for every new workspace member).
        foreach ($team->defaultChannels()->get() as $channel) {
            $this->joinChannel->handle($channel, $membership->user, announce: false);
        }
    }
}
