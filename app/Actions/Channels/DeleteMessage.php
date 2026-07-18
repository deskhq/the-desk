<?php

declare(strict_types=1);

namespace App\Actions\Channels;

use App\Data\MessageData;
use App\Enums\WebhookEvent;
use App\Events\MessageDeleted;
use App\Events\WebhookEventOccurred;
use App\Models\Channel;
use App\Models\Message;

class DeleteMessage
{
    public function __construct(private readonly UnpinMessage $unpinMessage) {}

    /**
     * Soft-delete a message and broadcast the tombstone.
     *
     * The row is kept so the client can render a "message deleted" placeholder
     * in place. The broadcast reuses {@see MessageData}, which blanks the body of
     * a trashed message, so no deleted content ever reaches other subscribers.
     */
    public function handle(Channel $channel, Message $message): void
    {
        // A soft delete leaves the row (and its FK-cascading children) in place,
        // so drop the reactions explicitly — a tombstone shows none, and they
        // would otherwise linger unreachable behind the deleted message.
        $message->reactions()->delete();

        // Likewise auto-remove any pin (a tombstone can't stay pinned) and
        // broadcast the unpin, so the masthead count and any open pins panel
        // update live. A no-op when the message wasn't pinned.
        $this->unpinMessage->handle($channel, $message);

        $message->delete();

        $message->loadMissing('user');
        $data = MessageData::fromMessage($message);
        event(new MessageDeleted($channel, $data));
        event(new WebhookEventOccurred(WebhookEvent::MessageDeleted, $channel, $data->toArray()));
    }
}
