<?php

use App\Enums\PresenceState;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the idle threshold reaches the browser', function (): void {
    config()->set('presence.away_after_minutes', 7);

    $this->actingAs(User::factory()->create())
        ->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('presence.awayAfterMinutes', 7));
});

test('a nonsensical threshold is floored at a minute rather than disabling idle detection', function (): void {
    config()->set('presence.away_after_minutes', 0);

    $this->actingAs(User::factory()->create())
        ->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('presence.awayAfterMinutes', 1));
});

test('the viewer reads their own presence off the shared auth prop', function (): void {
    $user = User::factory()->create(['presence_state' => PresenceState::Away]);

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('auth.user.presence', 'away'));
});
