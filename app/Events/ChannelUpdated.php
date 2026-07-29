<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChannelUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Announce that a channel's own details — its name, topic, or description —
     * were edited.
     *
     * A topic or name change also posts a system notice, but that only lands a
     * line in the timeline; this is what tells every open masthead that the
     * details it renders are stale. A description edit is silent in the
     * timeline and rides only this event.
     */
    public function __construct(public Channel $channel) {}

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
     * Get the data to broadcast: the channel's fresh details.
     *
     * The client refetches the channel prop rather than merging this payload —
     * the same details also feed the sidebar and the page title — so this
     * carries just enough for a listener to tell what changed.
     *
     * @return array{id: string, name: string|null, slug: string, topic: string|null, description: string|null}
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->channel->id,
            'name' => $this->channel->name,
            'slug' => $this->channel->slug,
            'topic' => $this->channel->topic,
            'description' => $this->channel->description,
        ];
    }
}
