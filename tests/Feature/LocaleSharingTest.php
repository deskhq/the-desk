<?php

use App\Enums\AppLocale;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the active locale is shared to inertia from the user preference', function (): void {
    $user = User::factory()->create(['locale' => AppLocale::French->value]);

    $this
        ->actingAs($user)
        ->get(route('locale.edit'))
        ->assertInertia(fn (Assert $page): Assert => $page->where('locale', 'fr'));

    expect(app()->getLocale())->toBe('fr');
});

test('guests fall back to the application default locale', function (): void {
    $this
        ->get(route('home'))
        ->assertInertia(fn (Assert $page): Assert => $page->where('locale', 'en'));
});

test('the active catalog rides the initial response as a once prop', function (): void {
    $user = User::factory()->create(['locale' => AppLocale::French->value]);

    $this
        ->actingAs($user)
        ->get(route('locale.edit'))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('locale', 'fr')
            ->has('translations')
        );
});
