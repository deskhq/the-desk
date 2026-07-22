<?php

use App\Actions\Teams\CreateTeam;
use App\Enums\PresenceState;
use App\Events\UserPresenceChanged;
use App\Models\User;
use Illuminate\Broadcasting\PresenceChannel;

test('it broadcasts on the presence channel of every team the user belongs to', function (): void {
    $user = User::factory()->create();
    $teamA = app(CreateTeam::class)->handle($user, 'Acme');
    $teamB = app(CreateTeam::class)->handle($user, 'Globex');

    $names = collect((new UserPresenceChanged($user->fresh(), PresenceState::Away))->broadcastOn())
        ->map(fn (PresenceChannel $channel): string => $channel->name);

    expect($names)->toContain('presence-team.'.$teamA->id)
        ->and($names)->toContain('presence-team.'.$teamB->id);
});

test('its payload is just the user id and their new state', function (): void {
    $user = User::factory()->create();

    $payload = (new UserPresenceChanged($user, PresenceState::Away))->broadcastWith();

    expect($payload)->toBe(['id' => $user->id, 'state' => 'away']);
});
