<?php

use App\Enums\ChimeSound;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('notification settings page is displayed with the selectable chime sounds', function (): void {
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->get(route('notifications.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('settings/Notifications')
            ->has('chimeSounds', count(ChimeSound::cases()))
            ->where('chimeSounds.0', ['value' => ChimeSound::Off->value, 'label' => 'Off'])
        );
});

test('the chime sound can be updated', function (): void {
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->patch(route('notifications.update'), [
            'chime_sound' => ChimeSound::Knock->value,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('notifications.edit'));

    expect($user->refresh()->chime_sound)->toBe(ChimeSound::Knock);
});

test('chimes can be disabled entirely', function (): void {
    $user = User::factory()->create(['chime_sound' => ChimeSound::Ping->value]);

    $this
        ->actingAs($user)
        ->patch(route('notifications.update'), [
            'chime_sound' => ChimeSound::Off->value,
        ])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->chime_sound)->toBe(ChimeSound::Off);
});

test('an unknown chime sound is rejected', function (): void {
    $user = User::factory()->create(['chime_sound' => ChimeSound::Ping->value]);

    $this
        ->actingAs($user)
        ->from(route('notifications.edit'))
        ->patch(route('notifications.update'), [
            'chime_sound' => 'foghorn',
        ])
        ->assertSessionHasErrors('chime_sound')
        ->assertRedirect(route('notifications.edit'));

    expect($user->refresh()->chime_sound)->toBe(ChimeSound::Ping);
});

test('a chime sound is required', function (): void {
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->from(route('notifications.edit'))
        ->patch(route('notifications.update'), [])
        ->assertSessionHasErrors('chime_sound');
});

test('guests cannot view the notification settings page', function (): void {
    $this->get(route('notifications.edit'))->assertRedirect(route('login'));
});
