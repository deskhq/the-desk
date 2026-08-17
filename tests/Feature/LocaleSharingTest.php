<?php

declare(strict_types=1);

use App\Enums\AppLocale;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The headers an SPA visit carries, declaring which once props the client
 * already holds — the shape the post-login redirect arrives in.
 *
 * @return array<string, string>
 */
function inertiaHeaders(string $loadedOnceProps): array
{
    return [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
        'X-Inertia-Except-Once-Props' => $loadedOnceProps,
    ];
}

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

test('the catalog once prop is keyed by the locale it holds', function (): void {
    $user = User::factory()->create(['locale' => AppLocale::French->value]);

    $page = $this
        ->actingAs($user)
        ->get(route('locale.edit'))
        ->viewData('page');

    expect($page['onceProps'])->toHaveKey('translations:fr');
});

test('the catalog is re-sent when the client only holds another locale', function (): void {
    $user = User::factory()->create(['locale' => AppLocale::French->value]);

    $response = $this
        ->actingAs($user)
        ->withHeaders(inertiaHeaders(loadedOnceProps: 'translations:en'))
        ->get(route('locale.edit'));

    $response->assertOk();

    expect($response->json('props.locale'))->toBe('fr')
        ->and($response->json('props.translations.Channels'))->toBe('Canaux');
});

test('the catalog stays out of visits once the client holds the matching locale', function (): void {
    $user = User::factory()->create(['locale' => AppLocale::French->value]);

    $response = $this
        ->actingAs($user)
        ->withHeaders(inertiaHeaders(loadedOnceProps: 'translations:fr'))
        ->get(route('locale.edit'));

    $response->assertOk();

    expect($response->json('props.locale'))->toBe('fr')
        ->and($response->json('props'))->not->toHaveKey('translations');
});
