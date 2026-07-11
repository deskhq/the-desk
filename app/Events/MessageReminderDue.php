<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired to a user when one of their message reminders comes due, so the open
 * workspace can slide in the nudge without a manual reload.
 *
 * It carries no content — only the signal — and rides the user's own private
 * `user.{id}` channel, so nothing about the reminded message leaks onto a shared
 * channel. The client responds by reloading the `firedReminders` prop (and the
 * pending `reminders` list), which the server recomputes, so the nudge is
 * correct even if the user has the workspace open on a different page.
 */
class MessageReminderDue implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(public string $userId) {}

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
}
