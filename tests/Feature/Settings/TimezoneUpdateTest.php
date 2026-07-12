<?php

use App\Models\User;

test('a user can set their timezone', function (): void {
    $user = User::factory()->create(['timezone' => null]);

    $response = $this
        ->actingAs($user)
        ->from(route('profile.edit'))
        ->patch(route('timezone.update'), [
            'timezone' => 'America/New_York',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->timezone)->toBe('America/New_York');
});

test('an invalid timezone is rejected', function (): void {
    $user = User::factory()->create(['timezone' => 'UTC']);

    $response = $this
        ->actingAs($user)
        ->from(route('profile.edit'))
        ->patch(route('timezone.update'), [
            'timezone' => 'Mars/Olympus_Mons',
        ]);

    $response->assertSessionHasErrors('timezone');

    expect($user->refresh()->timezone)->toBe('UTC');
});

test('the timezone is required', function (): void {
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->from(route('profile.edit'))
        ->patch(route('timezone.update'), [])
        ->assertSessionHasErrors('timezone');
});
