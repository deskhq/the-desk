<?php

namespace App\Events;

use App\Data\MessageData;
use App\Models\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageDeleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     *
     * The payload is the same {@see MessageData} DTO the HTTP endpoints return —
     * with the body blanked and `isDeleted` set — so every client can replace the
     * row it already renders with a tombstone, matched by `clientUuid`.
     */
    public function __construct(
        public Channel $channel,
        public MessageData $message,
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
     * Get the data to broadcast, matching the HTTP `MessageData` shape.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->message->toArray();
    }
}
