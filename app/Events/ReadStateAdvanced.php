<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a user's read pointer advances, so their *other* devices can drop
 * the channel's unread badge without waiting for a navigation.
 *
 * The increment direction already syncs across devices — every client watches
 * each `channel.{id}` and reloads the authoritative counts on a new message —
 * but clearing did not: reading on a phone left the desktop showing the channel
 * unread. This rides the reader's own private `user.{id}` channel, so nothing
 * about where they read leaks to peers, and it carries no counts: the client
 * responds by reloading the server-computed `channels` prop, reusing the
 * existing badge machinery rather than tracking counts of its own.
 *
 * Unlike {@see MessageRead}, this is dispatched regardless of the reader's
 * `share_read_receipts` preference — that toggle governs revealing read state
 * to *others*, not syncing a user's own devices.
 */
final class ReadStateAdvanced implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public string $userId,
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
            new PrivateChannel('user.'.$this->userId),
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
