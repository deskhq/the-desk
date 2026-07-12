<?php

declare(strict_types=1);

namespace App\Actions\Channels;

use App\Data\ReactionData;
use App\Events\MessageReactionChanged;
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
        $existing = $message->reactions()
            ->where('user_id', $user->id)
            ->where('emoji', $emoji)
            ->first();

        if ($existing !== null) {
            $existing->delete();
        } else {
            $message->reactions()->create([
                'user_id' => $user->id,
                'emoji' => $emoji,
            ]);
        }

        $message->load('reactions.user');

        event(new MessageReactionChanged($channel, $message->id, ReactionData::forMessage($message)));
    }
}
