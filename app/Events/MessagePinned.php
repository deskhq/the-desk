<?php

declare(strict_types=1);

namespace App\Events;

use App\Data\MentionData;
use App\Models\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessagePinned implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     *
     * A slim patch carrying just the target message id, its new pinned state, who
     * pinned it (null on an unpin), and the channel's fresh pin count — the client
     * merges it into the row it already renders (matched by id) and updates the
     * masthead's count badge, rather than rebuilding the whole {@see MessageData}.
     * A single broadcast serves every subscriber.
     */
    public function __construct(
        public Channel $channel,
        public string $messageId,
        public bool $pinned,
        public ?MentionData $pinnedBy,
        public int $pinCount,
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
     * Get the data to broadcast: the target message id, its pinned state, the
     * pinner, and the channel's pin count.
     *
     * @return array{messageId: string, pinned: bool, pinnedBy: array<string, mixed>|null, pinCount: int}
     */
    public function broadcastWith(): array
    {
        return [
            'messageId' => $this->messageId,
            'pinned' => $this->pinned,
            'pinnedBy' => $this->pinnedBy?->toArray(),
            'pinCount' => $this->pinCount,
        ];
    }
}
