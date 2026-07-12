<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired to a DM recipient when the conversation receives its first message, so
 * their sidebar can surface the freshly-visible DM without a manual reload.
 *
 * It carries no message content — only the channel id — and rides the
 * recipient's own private `user.{id}` channel, so nothing about the DM leaks
 * onto the shared team presence channel. The client responds by reloading the
 * `channels` prop, whose counts are recomputed server-side (so the unread badge
 * is correct even if the reload lands after the message itself).
 */
class DirectMessageStarted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public string $recipientId,
        public string $channelId,
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.'.$this->recipientId),
        ];
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, string>
     */
    public function broadcastWith(): array
    {
        return ['channelId' => $this->channelId];
    }
}
