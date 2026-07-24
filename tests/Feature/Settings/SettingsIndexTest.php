<?php

use App\Models\User;
use App\Support\SessionRegistry;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The settings index page that backs the mobile settings screen: below the
 * breakpoint every settings surface is reached from this list, so the route
 * renders a real page (with the viewer's active-session count for the
 * Security row's meta) instead of the old redirect to the profile pane.
 */
test('guests are redirected to the login page', function (): void {
    $this->get(route('settings.index'))->assertRedirect(route('login'));
});

test('the settings index renders with the current session counted', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withCookie(config('session.cookie'), Str::random(40))
        ->get(route('settings.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('settings/Index')
            ->where('sessionsCount', 1),
        );
});

test('other active devices raise the session count', function (): void {
    $user = User::factory()->create();

    app(SessionRegistry::class)->record(
        $user->id,
        Str::random(40),
        '203.0.113.10',
        'Mozilla/5.0 (iPhone; CPU iPhone OS 17_2 like Mac OS X) Version/17.2 Mobile Safari/604.1',
    );

    $this->actingAs($user)
        ->withCookie(config('session.cookie'), Str::random(40))
        ->get(route('settings.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('settings/Index')
            ->where('sessionsCount', 2),
        );
});
