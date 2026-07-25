<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('confirm password screen can be rendered', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('password.confirm'));

    $response->assertOk();

    $response->assertInertia(fn (Assert $page): Assert => $page
        ->component('auth/ConfirmPassword'),
    );
});

test('password confirmation requires authentication', function (): void {
    $response = $this->get(route('password.confirm'));

    $response->assertRedirect(route('login'));
});

test('confirming a password with no intended URL lands on the current team workspace', function (): void {
    // The guarded route someone was heading for is normally stored as the
    // intended URL; when it is not, the fallback used to be the public marketing
    // page rather than the workspace.
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('password.confirm.store'), ['password' => 'password'])
        ->assertRedirect(route('channels.index', ['team' => $user->currentTeam->slug]));
});

test('confirming a password over JSON answers without a redirect', function (): void {
    // An API client has no page to land on, so it gets Fortify's bare 201.
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('password.confirm.store'), ['password' => 'password'])
        ->assertCreated();
});

test('confirming a password honours the guarded URL it was raised for', function (): void {
    $user = User::factory()->create();

    $intended = route('security.edit');

    $this->actingAs($user)
        ->withSession(['url.intended' => $intended])
        ->post(route('password.confirm.store'), ['password' => 'password'])
        ->assertRedirect($intended);
});
