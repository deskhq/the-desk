<?php

use App\Models\User;

test('an authenticated visit to the login page lands on the current team workspace', function (): void {
    // The guest middleware short-circuits an already-signed-in visitor — a second
    // tab, a bookmark, the back button right after signing in. Without a redirect
    // callback it falls back to the route named `home`, i.e. the marketing page.
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('login'))
        ->assertRedirect(route('channels.index', ['team' => $user->currentTeam->slug]));
});

test('an authenticated post to the login route lands on the current team workspace', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('channels.index', ['team' => $user->currentTeam->slug]));
});

test('an authenticated visit to any guest-only auth route lands on the current team workspace', function (): void {
    // The callback is registered on the guest middleware itself, so every route
    // carrying it gets the workspace destination, not just login.
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('password.request'))
        ->assertRedirect(route('channels.index', ['team' => $user->currentTeam->slug]));
});

test('an authenticated visit falls back to the home page for a user with no team', function (): void {
    $user = User::factory()->create();
    $user->teams()->detach();
    $user->forceFill(['current_team_id' => null])->save();

    $this->actingAs($user->fresh())
        ->get(route('login'))
        ->assertRedirect(route('home'));
});
