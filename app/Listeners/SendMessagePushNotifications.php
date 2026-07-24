<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Data\MentionData;
use App\Events\MessageSent;
use App\Models\ChannelMember;
use App\Notifications\NewMessageNotification;
use App\Support\PushDecision;
use App\Support\WebPushConfig;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Fans a new message out to the channel members who should be alerted on a
 * device that isn't looking at the app.
 *
 * This is the app's first per-recipient server-side dispatch: until now a send
 * produced one channel-wide broadcast and every alerting decision was made in
 * the browser. Resolving recipients means a query per message, so it is
 * `ShouldQueue` — auto-discovered (the app has no EventServiceProvider) and run
 * off-request, well behind the latency-critical broadcast.
 *
 * The alerting rule itself lives in {@see PushDecision}, the server-side twin of
 * the client's `shouldChime`, so a push and a chime never disagree about whether
 * a message was worth interrupting someone for.
 */
class SendMessagePushNotifications implements ShouldQueue
{
    /**
     * Handle the event.
     */
    public function handle(MessageSent $event): void
    {
        if (! WebPushConfig::configured() || $event->message->type->isSystem()) {
            return;
        }

        $message = $event->message;

        /** @var list<string> $mentionedIds */
        $mentionedIds = array_map(
            static fn (MentionData $mention): string => $mention->id,
            $message->mentions,
        );

        // A thread-only reply is not ordinary channel traffic: it stays out of
        // the timeline and out of the sidebar's unread badge, so it only ever
        // alerts through the mention path.
        $isChannelMessage = $message->threadRootId === null || $message->sentToChannel;

        foreach ($this->recipients($event) as $member) {
            $recipient = $member->user;

            $shouldPush = PushDecision::shouldPush(
                isOwnMessage: $recipient->id === $message->user->id,
                isChannelMessage: $isChannelMessage,
                mentionsRecipient: in_array($recipient->id, $mentionedIds, true),
                muted: $member->muted,
                level: $member->notification_level,
                dndActive: $recipient->isDndActive(),
            );

            if ($shouldPush) {
                $recipient->notify(new NewMessageNotification($event->channel, $message));
            }
        }
    }

    /**
     * The channel's memberships worth even considering, with their user loaded.
     *
     * Narrowed in SQL to members who have a live account and at least one
     * subscribed device: a member with no device could not be reached whatever
     * the gate decided, so leaving them in would queue a notification job per
     * message per member for nothing. Everyone else — the author included — is
     * left for the gate to rule on, so the decision lives in one place.
     *
     * @return Collection<int, ChannelMember>
     */
    private function recipients(MessageSent $event): Collection
    {
        return ChannelMember::query()
            ->where('channel_id', $event->channel->id)
            ->whereHas('user', fn (Builder $query): Builder => $query
                ->whereNull('deactivated_at')
                ->whereHas('pushSubscriptions'))
            ->with('user')
            ->get();
    }
}
