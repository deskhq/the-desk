<?php

declare(strict_types=1);

namespace App\Actions\Channels;

use App\Data\ReactionData;
use App\Data\UserData;
use App\Enums\WebhookEvent;
use App\Events\MessageReactionChanged;
use App\Events\WebhookEventOccurred;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;

class ToggleReaction
{
    /**
     * Toggle a user's emoji reaction on a message, then broadcast the new summary.
     *
     * Flips the single `(message, user, emoji)` row on or off — a first tap adds
     * it, a second removes it — so a user reacts at most once per distinct emoji,
     * enforced by the table's unique constraint. Either way the message's
     * reactions are re-aggregated and broadcast so every open timeline and thread
     * patches the row's pills live, mirroring how message edits sync.
     */
    public function handle(Channel $channel, Message $message, User $user, string $emoji): void
    {
        $exists = $message->reactions()
            ->where('user_id', $user->id)
            ->where('emoji', $emoji)
            ->exists();

        if ($exists) {
            $this->remove($channel, $message, $user, $emoji);
        } else {
            $this->add($channel, $message, $user, $emoji);
        }
    }

    /**
     * Idempotently add a user's reaction. Relies on the `(message, user, emoji)`
     * unique constraint via `createOrFirst`, so two concurrent adds settle on the
     * one existing row instead of racing a duplicate insert; a re-add is a no-op
     * that broadcasts nothing.
     */
    public function add(Channel $channel, Message $message, User $user, string $emoji): void
    {
        $reaction = $message->reactions()->createOrFirst([
            'user_id' => $user->id,
            'emoji' => $emoji,
        ]);

        if (! $reaction->wasRecentlyCreated) {
            return;
        }

        $this->broadcast($channel, $message);

        event(new WebhookEventOccurred(WebhookEvent::ReactionAdded, $channel, [
            'channel_id' => $channel->id,
            'message_id' => $message->id,
            'emoji' => $emoji,
            'user' => UserData::fromUser($user)->toArray(),
        ]));
    }

    /**
     * Idempotently remove a user's reaction. A conditional delete is atomic, so a
     * missing reaction is a no-op that broadcasts nothing.
     */
    public function remove(Channel $channel, Message $message, User $user, string $emoji): void
    {
        $deleted = $message->reactions()
            ->where('user_id', $user->id)
            ->where('emoji', $emoji)
            ->delete();

        if ($deleted === 0) {
            return;
        }

        $this->broadcast($channel, $message);
    }

    /**
     * Re-aggregate the message's reactions and broadcast the new summary so every
     * open timeline and thread patches the row's pills live.
     */
    private function broadcast(Channel $channel, Message $message): void
    {
        $message->load('reactions.user');

        event(new MessageReactionChanged($channel, $message->id, ReactionData::forMessage($message)));
    }
}
