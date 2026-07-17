<?php

use App\Actions\Teams\CreateTeam;
use App\Events\UserProfileUpdated;
use App\Models\User;
use Illuminate\Broadcasting\PresenceChannel;

test('it broadcasts on the presence channel of every team the user belongs to', function (): void {
    $user = User::factory()->create();
    $teamA = app(CreateTeam::class)->handle($user, 'Acme');
    $teamB = app(CreateTeam::class)->handle($user, 'Globex');

    $names = collect((new UserProfileUpdated($user))->broadcastOn())
        ->map(fn (PresenceChannel $channel): string => $channel->name);

    expect($names)->toContain('presence-team.'.$teamA->id)
        ->and($names)->toContain('presence-team.'.$teamB->id);
});

test('its payload carries the user identity and resolved avatar', function (): void {
    $user = User::factory()->create([
        'avatar_url' => 'https://desk.test/storage/avatars/abc.jpg',
    ]);

    $payload = (new UserProfileUpdated($user))->broadcastWith();

    expect($payload['user']['id'])->toBe($user->id)
        ->and($payload['user']['name'])->toBe($user->name)
        ->and($payload['user']['avatar'])->toBe('https://desk.test/storage/avatars/abc.jpg');
});
