<?php

declare(strict_types=1);

namespace App\Actions\Channels;

use App\Enums\AuditAction;
use App\Events\AuditableActionOccurred;
use App\Models\Channel;
use App\Models\User;
use App\Support\ChannelMembership;
use Illuminate\Support\Facades\DB;

class RemoveChannelMember
{
    /**
     * Remove the user's membership from the channel.
     *
     * `$removedBy` names the member who removed someone *else*, which is what
     * makes the removal an administrative act worth auditing — pass it from any
     * surface that removes a member on their behalf. A member leaving of their
     * own accord (see {@see LeaveChannel}) leaves it null and records nothing.
     */
    public function handle(Channel $channel, User $user, ?User $removedBy = null): void
    {
        $removed = DB::transaction(fn (): bool => new ChannelMembership($channel, $user)->remove());

        if ($removed && $removedBy instanceof User && ! $removedBy->is($user)) {
            event(new AuditableActionOccurred($channel->team, $removedBy, AuditAction::ChannelMemberRemoved, $channel, [
                'channel_name' => $channel->name,
                'member_name' => $user->name,
            ]));
        }
    }
}
