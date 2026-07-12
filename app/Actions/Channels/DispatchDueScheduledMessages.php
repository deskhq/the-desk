<?php

namespace App\Actions\Channels;

use App\Enums\ScheduledMessageStatus;
use App\Models\ScheduledMessage;
use Illuminate\Support\Facades\Gate;

class DispatchDueScheduledMessages
{
    public function __construct(private readonly PostMessage $postMessage) {}

    /**
     * Deliver every scheduled message whose send time has arrived.
     *
     * Runs once per minute. Only pending rows that are due are claimed, so a
     * cancelled or already-sent row is never touched, and each is delivered
     * through the normal send path so it broadcasts, resolves mentions, and
     * unfurls links exactly like an immediate send.
     */
    public function handle(): void
    {
        ScheduledMessage::query()
            ->due()
            ->orderBy('send_at')
            ->get()
            ->each(fn (ScheduledMessage $scheduled) => $this->deliver($scheduled));
    }

    /**
     * Deliver a single due row, or mark it failed when it can no longer be sent.
     *
     * When the author can no longer post to the channel — it was archived, or
     * they were removed — the row is failed rather than delivered. A since-deleted
     * reply target is dropped so the message still sends, just without its quote.
     */
    private function deliver(ScheduledMessage $scheduled): void
    {
        $channel = $scheduled->channel;
        $author = $scheduled->user;

        if (! Gate::forUser($author)->allows('postMessage', $channel)) {
            $scheduled->update([
                'status' => ScheduledMessageStatus::Failed,
                'failed_at' => now(),
                'failure_reason' => 'The author can no longer post to this channel.',
            ]);

            return;
        }

        $replyToId = $scheduled->reply_to_id;

        if ($replyToId !== null && ! $channel->messages()->whereKey($replyToId)->exists()) {
            $replyToId = null;
        }

        // PostMessage is keyed on the client uuid, so even if an overlapping tick
        // delivered this row already the send resolves to the existing message
        // without a duplicate or a second broadcast — at-most-once delivery.
        $this->postMessage->handle(
            channel: $channel,
            author: $author,
            body: $scheduled->body,
            clientUuid: $scheduled->client_uuid,
            replyToId: $replyToId,
            clearDraft: false,
        );

        $scheduled->update([
            'status' => ScheduledMessageStatus::Sent,
            'sent_at' => now(),
        ]);
    }
}
