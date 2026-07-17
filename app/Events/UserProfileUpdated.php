<?php

declare(strict_types=1);

namespace App\Events;

use App\Data\UserData;
use App\Models\Team;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A user's profile identity changed (today: their avatar). Broadcast on every
 * team-presence channel they belong to so other open clients re-read the avatar
 * on every surface without a reload.
 *
 * This is the shared "presence & identity" event for the epic: later features
 * (custom status, away/idle, do-not-disturb) add fields to its payload rather
 * than inventing their own event.
 */
class UserProfileUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public User $user) {}

    /**
     * The presence channel of each team the user belongs to — every teammate
     * with that team open receives the update.
     *
     * @return array<int, PresenceChannel>
     */
    public function broadcastOn(): array
    {
        return $this->user->teams
            ->map(fn (Team $team): PresenceChannel => new PresenceChannel('team.'.$team->id))
            ->all();
    }

    /**
     * @return array{user: array<string, mixed>}
     */
    public function broadcastWith(): array
    {
        return ['user' => UserData::fromUser($this->user)->toArray()];
    }
}
