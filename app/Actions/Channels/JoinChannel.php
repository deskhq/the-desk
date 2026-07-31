<?php

namespace App\Actions\Channels;

use App\Data\UserData;
use App\Enums\AuditAction;
use App\Enums\MessageType;
use App\Enums\WebhookEvent;
use App\Events\AuditableActionOccurred;
use App\Events\WebhookEventOccurred;
use App\Models\Channel;
use App\Models\ChannelMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class JoinChannel
{
    public function __construct(private readonly PostSystemMessage $postSystemMessage) {}

    /**
     * Add the user to the channel, returning the (existing or new) membership.
     *
     * A genuinely new membership posts a "member joined" system notice to the
     * channel timeline; re-joining an existing membership is a no-op that posts
     * nothing, so the notice only appears once per join.
     *
     * `$announce` is false for the two structural joins that aren't a user
     * deciding to join a channel: creating a channel (the creator seeds it, they
     * don't "join" it) and the team-onboarding auto-join to the protected
     * #general channel (which would otherwise post a join notice for every new
     * workspace member). Only an explicit channel join announces.
     *
     * `$addedBy` names the member who put someone *else* in the channel, which
     * is what makes the membership an administrative act worth auditing — pass
     * it from any surface that adds a member on their behalf. A plain join, and
     * the structural joins above, leave it null and record nothing; the webhook
     * fires either way, because a subscriber cares that membership changed, not
     * who decided it.
     */
    public function handle(Channel $channel, User $user, bool $announce = true, ?User $addedBy = null): ChannelMember
    {
        $membership = DB::transaction(fn (): ChannelMember => $channel->channelMembers()->firstOrCreate([
            'user_id' => $user->id,
        ]));

        if ($membership->wasRecentlyCreated) {
            if ($announce) {
                $this->postSystemMessage->handle($channel, $user, MessageType::MemberJoined);
            }

            event(new WebhookEventOccurred(WebhookEvent::ChannelMemberAdded, $channel, [
                'channel_id' => $channel->id,
                'user' => UserData::fromUser($user)->toArray(),
            ]));

            if ($addedBy instanceof User && ! $addedBy->is($user)) {
                event(new AuditableActionOccurred($channel->team, $addedBy, AuditAction::ChannelMemberAdded, $channel, [
                    'channel_name' => $channel->name,
                    'member_name' => $user->name,
                ]));
            }
        }

        return $membership;
    }
}
