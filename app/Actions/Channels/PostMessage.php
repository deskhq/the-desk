<?php

namespace App\Actions\Channels;

use App\Data\MessageData;
use App\Events\MessageSent;
use App\Events\MessageUpdated;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;

class PostMessage
{
    public function __construct(
        private SyncMentions $syncMentions,
        private SyncLinkPreviews $syncLinkPreviews,
    ) {}

    /**
     * Post a message to a channel on behalf of a user.
     *
     * Keyed on the client-generated uuid so a resent optimistic message resolves
     * to the row that already exists instead of creating a duplicate. Only a
     * genuinely new message parses its mentions and broadcasts, keeping the retry
     * path side-effect free.
     *
     * When `$replyToId` is set the message renders as an inline quote of that
     * parent. When `$forwardedFromId` is set the message forwards that (possibly
     * cross-channel) source, rendered with its attribution and quote; the body is
     * an optional note. When `$threadRootId` is set the message is a thread reply:
     * it stays out of the main timeline unless `$sentToChannel` is true, and it
     * bumps the root's denormalized reply count / last-reply time. The request
     * layer has already checked every reference points at a live, permitted message.
     *
     * A main-composer send clears the author's channel draft, since scheduling or
     * sending consumes its text. A delayed delivery (the scheduler replaying a
     * scheduled message) passes `$clearDraft: false` so it never wipes a draft the
     * author has typed in the meantime.
     */
    public function handle(Channel $channel, User $author, string $body, string $clientUuid, ?string $replyToId = null, ?string $forwardedFromId = null, ?string $threadRootId = null, bool $sentToChannel = false, bool $clearDraft = true): Message
    {
        $message = $channel->messages()->firstOrCreate(
            ['client_uuid' => $clientUuid],
            [
                'user_id' => $author->id,
                'body' => $body,
                'reply_to_id' => $replyToId,
                'forwarded_from_id' => $forwardedFromId,
                'thread_root_id' => $threadRootId,
                'sent_to_channel' => $threadRootId !== null && $sentToChannel,
            ],
        );

        if ($message->wasRecentlyCreated) {
            $this->syncMentions->handle($channel, $message);
            $this->syncLinkPreviews->handle($message);
            $message->loadMessageDataRelations();
            MessageSent::dispatch($channel, MessageData::fromMessage($message));

            if ($threadRootId !== null) {
                $this->bumpThreadRoot($channel, $threadRootId);
            } elseif ($clearDraft) {
                // A message sent from the main composer clears its channel draft;
                // a thread reply leaves the channel draft alone (it isn't its text),
                // and a delayed scheduled delivery leaves it alone too.
                $author->channels()->updateExistingPivot($channel->id, ['draft' => null]);
            }
        }

        return $message;
    }

    /**
     * Advance the root's reply aggregates and broadcast the fresh count so open
     * timelines patch the root's "N replies" affordance live.
     */
    private function bumpThreadRoot(Channel $channel, string $threadRootId): void
    {
        $root = $channel->messages()->withTrashed()->findOrFail($threadRootId);
        $root->forceFill(['reply_count' => $root->reply_count + 1, 'last_reply_at' => now()])->save();

        $root->loadMessageDataRelations();
        MessageUpdated::dispatch($channel, MessageData::fromMessage($root));
    }
}
