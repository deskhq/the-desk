<?php

declare(strict_types=1);

namespace App\Actions\Channels;

use App\Models\Channel;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RemoveChannelMember
{
    /**
     * Remove the user's membership from the channel.
     */
    public function handle(Channel $channel, User $user): void
    {
        DB::transaction(function () use ($channel, $user): void {
            $channel->channelMembers()
                ->where('user_id', $user->id)
                ->delete();
        });
    }
}
