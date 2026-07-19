<?php

declare(strict_types=1);

namespace App\Events;

use App\Data\UserData;
use App\Models\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserTyping implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     *
     * Announces that `typist` is composing a message in the channel, so peers
     * can show the "X is typing…" indicator. The identity is derived server-side
     * from the authenticated user — never from a client-supplied payload — so a
     * channel member cannot impersonate another member's typing.
     */
    public function __construct(
        public Channel $channel,
        public UserData $typist,
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('channel.'.$this->channel->id),
        ];
    }

    /**
     * Get the data to broadcast: who is typing, in the shape the indicator
     * roster consumes.
     *
     * @return array{id: string, name: string}
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->typist->id,
            'name' => $this->typist->name,
        ];
    }
}
