<?php

declare(strict_types=1);

namespace App\Actions\Channels;

use App\Enums\MessageType;
use App\Models\Channel;
use App\Models\User;

class LeaveChannel
{
    public function __construct(
        private readonly RemoveChannelMember $removeChannelMember,
        private readonly PostSystemMessage $postSystemMessage,
    ) {}

    /**
     * Remove the user's own membership from the channel and record a "member
     * left" system notice in the timeline.
     *
     * Reuses the pivot-delete from {@see RemoveChannelMember}; the leaver stays
     * the notice's actor, so the timeline can render ":name left the channel"
     * even though they are no longer a member.
     */
    public function handle(Channel $channel, User $user): void
    {
        $this->removeChannelMember->handle($channel, $user);
        $this->postSystemMessage->handle($channel, $user, MessageType::MemberLeft);
    }
}
