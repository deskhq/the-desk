<?php

declare(strict_types=1);

namespace App\Actions\Channels;

use App\Data\MessageData;
use App\Events\MessageUpdated;
use App\Models\Channel;
use App\Models\Message;

class EditMessage
{
    public function __construct(
        private readonly SyncMentions $syncMentions,
        private readonly SyncLinkPreviews $syncLinkPreviews,
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

        $message->loadMessageDataRelations();
        event(new MessageUpdated($channel, MessageData::fromMessage($message)));

        return $message;
    }
}
