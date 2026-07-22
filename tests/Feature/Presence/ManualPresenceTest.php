<?php

use App\Enums\PresenceState;
use App\Events\UserPresenceChanged;
use App\Models\User;
use App\Support\PresenceRegistry;
use Illuminate\Support\Facades\Event;

test('a user can set themselves away', function (): void {
    Event::fake([UserPresenceChanged::class]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->from('/')
        ->put(route('presence.update'), ['state' => 'away'])
        ->assertRedirect('/');

    expect($user->fresh()->presence_state)->toBe(PresenceState::Away);

    Event::assertDispatched(
        UserPresenceChanged::class,
        fn (UserPresenceChanged $event): bool => $event->user->is($user) && $event->state === PresenceState::Away,
    );
});

test('a user can set themselves active again', function (): void {
    Event::fake([UserPresenceChanged::class]);

    $user = User::factory()->create(['presence_state' => PresenceState::Away]);

    $this->actingAs($user)
        ->from('/')
        ->put(route('presence.update'), ['state' => 'active'])
        ->assertRedirect('/');

    expect($user->fresh()->presence_state)->toBe(PresenceState::Active);

    Event::assertDispatched(
        UserPresenceChanged::class,
        fn (UserPresenceChanged $event): bool => $event->state === PresenceState::Active,
    );
});

test('clearing a manual away reports the state the connections say', function (): void {
    Event::fake([UserPresenceChanged::class]);

    $user = User::factory()->create(['presence_state' => PresenceState::Away]);
    app(PresenceRegistry::class)->record($user->id, 'laptop', PresenceState::Away);

    $this->actingAs($user)->put(route('presence.update'), ['state' => 'active']);

    Event::assertDispatched(
        UserPresenceChanged::class,
        fn (UserPresenceChanged $event): bool => $event->state === PresenceState::Away,
    );
});

test('an unknown state is rejected', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put(route('presence.update'), ['state' => 'lurking'])
        ->assertSessionHasErrors('state');
});

test('a guest cannot set a presence', function (): void {
    $this->put(route('presence.update'), ['state' => 'away'])->assertRedirect(route('login'));
});
