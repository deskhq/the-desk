<?php

declare(strict_types=1);

namespace App\Events;

use App\Data\MessageData;
use App\Data\PollData;
use App\Models\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PollVoteChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     *
     * A slim payload carrying the poll message's id and its freshly re-aggregated
     * poll — the client merges it into the row it already renders, matched by id,
     * rather than rebuilding the whole {@see MessageData}. The payload
     * is viewer-free (public rosters let each subscriber derive its own selection,
     * and anonymous polls carry no roster), so one broadcast serves everyone. A
     * close rides the same event, since the poll carries its `closedAt`.
     */
    public function __construct(
        public Channel $channel,
        public string $messageId,
        public PollData $poll,
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
     * Get the data to broadcast: the target message id and its poll payload.
     *
     * @return array{messageId: string, poll: array<string, mixed>}
     */
    public function broadcastWith(): array
    {
        return [
            'messageId' => $this->messageId,
            'poll' => $this->poll->toArray(),
        ];
    }
}
