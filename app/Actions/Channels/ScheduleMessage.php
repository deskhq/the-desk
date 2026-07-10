<?php

namespace App\Actions\Channels;

use App\Models\Channel;
use App\Models\ScheduledMessage;
use App\Models\User;
use Illuminate\Support\Carbon;

class ScheduleMessage
{
    /**
     * Schedule a message for future delivery to a channel on behalf of a user.
     *
     * Nothing is posted now: the row is stored pending and the per-minute
     * dispatcher delivers it through the normal send path once `$sendAt` arrives.
     * Scheduling consumes the composer's text like an immediate send, so the
     * author's channel draft is cleared. The `$clientUuid` is carried through to
     * delivery so the eventual send dedupes exactly like an immediate one.
     */
    public function handle(Channel $channel, User $author, string $body, string $clientUuid, Carbon $sendAt, ?string $replyToId = null): ScheduledMessage
    {
        $scheduled = $channel->scheduledMessages()->create([
            'user_id' => $author->id,
            'body' => $body,
            'client_uuid' => $clientUuid,
            'reply_to_id' => $replyToId,
            'send_at' => $sendAt,
        ]);

        $author->channels()->updateExistingPivot($channel->id, ['draft' => null]);

        return $scheduled;
    }
}
