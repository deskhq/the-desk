<?php

namespace App\Actions\Channels;

use App\Data\MessageData;
use App\Events\MessageUpdated;
use App\Models\Channel;
use App\Models\Message;

class EditMessage
{
    public function __construct(
        private SyncMentions $syncMentions,
        private SyncLinkPreviews $syncLinkPreviews,
    ) {}

    /**
     * Edit a message's body on behalf of its author.
     *
     * Stamps `edited_at` so the client can show the "(edited)" marker, re-syncs
     * the mention rows against the new body (adding new, removing stale), then
     * broadcasts the new state so every subscriber reconciles the row in place.
     */
    public function handle(Channel $channel, Message $message, string $body): Message
    {
        $message->update([
            'body' => $body,
            'edited_at' => now(),
        ]);

        $this->syncMentions->handle($channel, $message);
        $this->syncLinkPreviews->handle($message);

        $message->loadMissing('user');
        $message->load(['mentionedUsers', 'linkPreviews']);
        MessageUpdated::dispatch($channel, MessageData::fromMessage($message));

        return $message;
    }
}
