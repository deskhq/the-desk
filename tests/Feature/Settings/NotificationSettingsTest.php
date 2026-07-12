<?php

use App\Enums\ChimeSound;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the appearance & notifications page is displayed with the selectable chime sounds', function (): void {
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->get(route('appearance.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('settings/Appearance')
            ->has('chimeSounds', count(ChimeSound::cases()))
            ->where('chimeSounds.0', ['value' => ChimeSound::Off->value, 'label' => 'Off'])
        );
});

test('the legacy notifications route redirects to the appearance page', function (): void {
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->get(route('notifications.edit'))
        ->assertRedirect(route('appearance.edit'));
});

test('the chime sound can be updated', function (): void {
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->patch(route('notifications.update'), [
            'chime_sound' => ChimeSound::Knock->value,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('appearance.edit'));

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
        ->from(route('appearance.edit'))
        ->patch(route('notifications.update'), [
            'chime_sound' => 'foghorn',
        ])
        ->assertSessionHasErrors('chime_sound')
        ->assertRedirect(route('appearance.edit'));

    expect($user->refresh()->chime_sound)->toBe(ChimeSound::Ping);
});

test('a chime sound is required', function (): void {
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->from(route('appearance.edit'))
        ->patch(route('notifications.update'), [])
        ->assertSessionHasErrors('chime_sound');
});

test('guests cannot view the appearance & notifications page', function (): void {
    $this->get(route('appearance.edit'))->assertRedirect(route('login'));
});

test('guests are redirected from the legacy notifications route', function (): void {
    $this->get(route('notifications.edit'))->assertRedirect(route('login'));
});
