<?php

use App\Data\UserData;
use App\Enums\PresenceState;
use App\Models\User;
use App\Support\PresenceRegistry;

test('a new user is active by default', function (): void {
    $user = User::factory()->create();

    expect($user->presence_state)->toBe(PresenceState::Active)
        ->and($user->effectivePresence())->toBe(PresenceState::Active);
});

test('a manual away wins over an active connection', function (): void {
    $user = User::factory()->create(['presence_state' => PresenceState::Away]);

    app(PresenceRegistry::class)->record($user->id, 'laptop', PresenceState::Active);

    expect($user->effectivePresence())->toBe(PresenceState::Away);
});

test('an unset manual state defers to the connection aggregate', function (): void {
    $user = User::factory()->create(['presence_state' => PresenceState::Active]);

    app(PresenceRegistry::class)->record($user->id, 'laptop', PresenceState::Away);

    expect($user->effectivePresence())->toBe(PresenceState::Away);
});

test('the presence rides the user DTO every surface renders from', function (): void {
    $user = User::factory()->create(['presence_state' => PresenceState::Away]);

    expect(UserData::fromUser($user)->presence)->toBe(PresenceState::Away);
});
