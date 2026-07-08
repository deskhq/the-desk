<?php

namespace App\Actions\Channels;

use App\Models\Channel;
use App\Models\ChannelMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class JoinChannel
{
    /**
     * Add the user to the channel, returning the (existing or new) membership.
     */
    public function handle(Channel $channel, User $user): ChannelMember
    {
        return DB::transaction(function () use ($channel, $user) {
            return $channel->channelMembers()->firstOrCreate([
                'user_id' => $user->id,
            ]);
        });
    }
}
