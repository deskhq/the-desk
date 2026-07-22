<?php

declare(strict_types=1);

namespace App\Events;

use App\Data\UserData;
use App\Enums\PresenceState;
use App\Models\Team;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A user flipped between active and away, on every team they belong to.
 *
 * Deliberately a separate, tiny event rather than a field on
 * {@see UserProfileUpdated}, whose listener answers with a debounced reload of
 * *every* Inertia prop. That is proportionate for an avatar or a custom status,
 * which change a few times a day; auto-idle fires on every idle↔active
 * transition, so reusing that path would mean hundreds of full-prop reloads per
 * client over a lunch hour in a busy workspace. Clients patch a local map from
 * this payload instead and re-render only the dots.
 *
 * The presence still rides `UserProfileUpdated` (via {@see UserData})
 * so a client that reloads for any other reason lands on the right state.
 */
class UserPresenceChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public User $user, public PresenceState $state) {}

    /**
     * The presence channel of each team the user belongs to — every teammate
     * with that team open receives the flip.
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
     * @return array{id: string, state: string}
     */
    public function broadcastWith(): array
    {
        return ['id' => $this->user->id, 'state' => $this->state->value];
    }
}
