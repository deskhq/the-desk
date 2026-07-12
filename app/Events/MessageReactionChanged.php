<?php

declare(strict_types=1);

namespace App\Events;

use App\Data\MessageData;
use App\Data\ReactionData;
use App\Models\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageReactionChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     *
     * A slim payload carrying just the message id and its freshly aggregated
     * reaction summary — the client merges it into the row it already renders,
     * matched by id, rather than rebuilding the whole {@see MessageData}.
     * The summary is viewer-free (each entry lists its reactors), so a single
     * broadcast serves every subscriber and each derives its own "did I react".
     *
     * @param  array<int, ReactionData>  $reactions
     */
    public function __construct(
        public Channel $channel,
        public string $messageId,
        public array $reactions,
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
     * Get the data to broadcast: the target message id and its reaction summary.
     *
     * @return array{messageId: string, reactions: array<int, array<string, mixed>>}
     */
    public function broadcastWith(): array
    {
        return [
            'messageId' => $this->messageId,
            'reactions' => array_map(fn (ReactionData $reaction): array => $reaction->toArray(), $this->reactions),
        ];
    }
}
