<?php

use App\Enums\AppLocale;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('localization settings page is displayed with the selectable locales', function () {
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->get(route('locale.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Localization')
            ->has('locales', count(AppLocale::cases()))
            ->where('locales.0', ['value' => AppLocale::English->value, 'label' => 'English'])
        );
});

test('the locale can be updated', function () {
    $user = User::factory()->create(['locale' => AppLocale::English->value]);

    $this
        ->actingAs($user)
        ->patch(route('locale.update'), [
            'locale' => AppLocale::French->value,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('locale.edit'));

    expect($user->refresh()->locale)->toBe(AppLocale::French);
});

test('an unknown locale is rejected', function () {
    $user = User::factory()->create(['locale' => AppLocale::English->value]);

    $this
        ->actingAs($user)
        ->from(route('locale.edit'))
        ->patch(route('locale.update'), [
            'locale' => 'xx',
        ])
        ->assertSessionHasErrors('locale')
        ->assertRedirect(route('locale.edit'));

    expect($user->refresh()->locale)->toBe(AppLocale::English);
});

test('a locale is required', function () {
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->from(route('locale.edit'))
        ->patch(route('locale.update'), [])
        ->assertSessionHasErrors('locale');
});

test('guests cannot view the localization settings page', function () {
    $this->get(route('locale.edit'))->assertRedirect(route('login'));
});
