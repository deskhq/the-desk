<?php

declare(strict_types=1);

namespace App\Actions\Channels;

use App\Data\MessageData;
use App\Enums\AuditAction;
use App\Enums\WebhookEvent;
use App\Events\AuditableActionOccurred;
use App\Events\MessageDeleted;
use App\Events\WebhookEventOccurred;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;

final readonly class DeleteMessage
{
    public function __construct(private UnpinMessage $unpinMessage) {}

    /**
     * Soft-delete a message and broadcast the tombstone.
     *
     * The row is kept so the client can render a "message deleted" placeholder
     * in place. The broadcast reuses {@see MessageData}, which blanks the body of
     * a trashed message, so no deleted content ever reaches other subscribers.
     *
     * `$deletedBy` names who deleted it. Only a moderation deletion — someone
     * removing a message that isn't theirs — is audited; a member deleting their
     * own message is not an admin action.
     */
    public function handle(Channel $channel, Message $message, ?User $deletedBy = null): void
    {
        $message->loadMissing('user');
        $author = $message->user;

        // A soft delete leaves the row (and its FK-cascading children) in place,
        // so drop the reactions explicitly — a tombstone shows none, and they
        // would otherwise linger unreachable behind the deleted message.
        $message->reactions()->delete();

        // A poll dies with its message: hard-delete the poll so its options and
        // votes cascade away, since the tombstone renders no poll and they would
        // otherwise linger unreachable behind the deleted message. A no-op for a
        // non-poll message.
        $message->poll()->delete();

        // Likewise auto-remove any pin (a tombstone can't stay pinned) and
        // broadcast the unpin, so the masthead count and any open pins panel
        // update live. A no-op when the message wasn't pinned.
        $this->unpinMessage->handle($channel, $message);

        $message->delete();

        $data = MessageData::fromMessage($message);
        event(new MessageDeleted($channel, $data));
        event(new WebhookEventOccurred(WebhookEvent::MessageDeleted, $channel, $data->toArray()));

        if ($deletedBy instanceof User && ! $deletedBy->is($author)) {
            event(new AuditableActionOccurred($channel->team, $deletedBy, AuditAction::MessageDeleted, $message, [
                'channel_name' => $channel->name,
                'author_name' => $author->name,
            ]));
        }
    }
}
