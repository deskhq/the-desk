<?php

declare(strict_types=1);

namespace App\Actions\Channels;

use App\Data\MessageData;
use App\Enums\MessageType;
use App\Events\MessageSent;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Str;

class PostSystemMessage
{
    /**
     * Post a system notice (member joined / left) to a channel and broadcast it.
     *
     * The row's `user_id` is the actor whose action it records; the `type` flag
     * is what makes it render as a centered, inert notice rather than that user's
     * chat bubble. No rendered English is ever persisted — the timeline builds
     * the localized line client-side from the type and actor — so the body stays
     * empty for a notice that needs no subject, and otherwise carries only the
     * author's own words the line quotes (the new topic, the new channel name).
     * The notice rides the same {@see MessageSent} broadcast as a normal message,
     * so it appears live in open timelines.
     */
    public function handle(Channel $channel, User $actor, MessageType $type, string $subject = ''): Message
    {
        $message = $channel->messages()->create([
            'user_id' => $actor->id,
            'client_uuid' => (string) Str::uuid(),
            'body' => $subject,
            'type' => $type,
        ]);

        $message->loadMessageDataRelations();
        event(new MessageSent($channel, MessageData::fromMessage($message)));

        return $message;
    }
}
