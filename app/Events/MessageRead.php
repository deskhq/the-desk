<?php

namespace App\Events;

use App\Data\UserData;
use App\Models\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageRead implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     *
     * Announces that `reader` has advanced their read pointer in the channel to
     * `lastReadMessageId`, so peers can update the "Seen by" affordance in
     * realtime. Only dispatched for users who share read receipts.
     */
    public function __construct(
        public Channel $channel,
        public UserData $reader,
        public string $lastReadMessageId,
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
     * Get the data to broadcast: who read, and how far.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'reader' => $this->reader->toArray(),
            'lastReadMessageId' => $this->lastReadMessageId,
        ];
    }
}
