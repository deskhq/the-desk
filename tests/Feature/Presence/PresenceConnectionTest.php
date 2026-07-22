<?php

use App\Enums\PresenceState;
use App\Events\UserPresenceChanged;
use App\Models\User;
use App\Support\PresenceRegistry;
use Illuminate\Support\Facades\Event;

test('a tab reporting itself idle turns the user away for teammates', function (): void {
    Event::fake([UserPresenceChanged::class]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('presence.report'), ['connection' => 'tab-a', 'state' => 'away'])
        ->assertNoContent();

    expect(app(PresenceRegistry::class)->aggregate($user->id))->toBe(PresenceState::Away);

    Event::assertDispatched(
        UserPresenceChanged::class,
        fn (UserPresenceChanged $event): bool => $event->state === PresenceState::Away,
    );
});

test('a second tab going idle broadcasts nothing because the aggregate is unchanged', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson(route('presence.report'), ['connection' => 'tab-a', 'state' => 'away']);

    Event::fake([UserPresenceChanged::class]);

    $this->actingAs($user)->postJson(route('presence.report'), ['connection' => 'tab-b', 'state' => 'away']);

    Event::assertNotDispatched(UserPresenceChanged::class);
});

test('a tab going idle while another stays active broadcasts nothing', function (): void {
    Event::fake([UserPresenceChanged::class]);

    $user = User::factory()->create();

    $this->actingAs($user)->postJson(route('presence.report'), ['connection' => 'laptop', 'state' => 'active']);
    $this->actingAs($user)->postJson(route('presence.report'), ['connection' => 'phone', 'state' => 'away']);

    expect(app(PresenceRegistry::class)->aggregate($user->id))->toBe(PresenceState::Active);

    Event::assertNotDispatched(UserPresenceChanged::class);
});

test('activity on any tab brings the user back', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson(route('presence.report'), ['connection' => 'tab-a', 'state' => 'away']);

    Event::fake([UserPresenceChanged::class]);

    $this->actingAs($user)->postJson(route('presence.report'), ['connection' => 'tab-a', 'state' => 'active']);

    Event::assertDispatched(
        UserPresenceChanged::class,
        fn (UserPresenceChanged $event): bool => $event->state === PresenceState::Active,
    );
});

test('a manual away is not undone by a tab reporting activity', function (): void {
    Event::fake([UserPresenceChanged::class]);

    $user = User::factory()->create(['presence_state' => PresenceState::Away]);

    $this->actingAs($user)->postJson(route('presence.report'), ['connection' => 'tab-a', 'state' => 'active']);

    expect($user->fresh()->effectivePresence())->toBe(PresenceState::Away);

    Event::assertNotDispatched(UserPresenceChanged::class);
});

test('closing the last idle tab returns the user to active', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson(route('presence.report'), ['connection' => 'tab-a', 'state' => 'away']);

    Event::fake([UserPresenceChanged::class]);

    $this->actingAs($user)
        ->postJson(route('presence.release'), ['connection' => 'tab-a'])
        ->assertNoContent();

    Event::assertDispatched(
        UserPresenceChanged::class,
        fn (UserPresenceChanged $event): bool => $event->state === PresenceState::Active,
    );
});

test('closing an active tab while an idle one remains turns the user away', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson(route('presence.report'), ['connection' => 'laptop', 'state' => 'active']);
    $this->actingAs($user)->postJson(route('presence.report'), ['connection' => 'phone', 'state' => 'away']);

    Event::fake([UserPresenceChanged::class]);

    $this->actingAs($user)->postJson(route('presence.release'), ['connection' => 'laptop']);

    Event::assertDispatched(
        UserPresenceChanged::class,
        fn (UserPresenceChanged $event): bool => $event->state === PresenceState::Away,
    );
});

test('a report needs a connection key and a known state', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('presence.report'), ['state' => 'away'])
        ->assertJsonValidationErrors('connection');

    $this->actingAs($user)
        ->postJson(route('presence.report'), ['connection' => 'tab-a', 'state' => 'lurking'])
        ->assertJsonValidationErrors('state');
});

test('a guest cannot report a connection', function (): void {
    $this->postJson(route('presence.report'), ['connection' => 'tab-a', 'state' => 'away'])
        ->assertUnauthorized();
});
