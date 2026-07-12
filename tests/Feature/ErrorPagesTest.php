<?php

use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Register a throwaway web route that aborts with the given status, and return
 * its URL. The `web` group ensures the Inertia middleware is in play so the
 * exception handler can resolve shared data.
 */
function abortingRoute(int $status): string
{
    $path = '_test/abort/'.$status;

    Route::middleware('web')->get($path, fn () => abort($status));

    return '/'.$path;
}

test('the 404 fallback renders the branded Inertia error page', function (): void {
    $this->get('/this-route-does-not-exist')
        ->assertStatus(404)
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('Error')
            ->where('status', 404)
            // Shared data resolved via withSharedData() — proves the page
            // inherits the app shell props (name, locale, translations).
            ->where('name', config('app.name'))
            ->where('locale', 'en')
            ->has('translations')
        );
});

test('the common HTTP statuses each render the Inertia error page', function (int $status): void {
    $this->get(abortingRoute($status))
        ->assertStatus($status)
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('Error')
            ->where('status', $status)
        );
})->with([403, 419, 429]);

test('API requests still receive JSON, not the Inertia error page', function (): void {
    $response = $this->getJson('/this-route-does-not-exist')
        ->assertStatus(404)
        ->assertHeader('content-type', 'application/json');

    // JSON, not the Inertia error component.
    expect($response->getContent())->not->toContain('"component":"Error"');
});

test('expectsJson requests fall through to the JSON response', function (): void {
    $this->get(abortingRoute(403), ['Accept' => 'application/json'])
        ->assertStatus(403)
        ->assertHeader('content-type', 'application/json');
});

test('the 500 fallback renders a self-contained branded Blade page', function (): void {
    config(['app.debug' => false]);

    Route::middleware('web')->get('_test/boom', function (): void {
        throw new RuntimeException('boom');
    });

    $response = $this->get('/_test/boom');

    $response->assertStatus(500)
        ->assertSee('Something broke on our desk')
        ->assertSee('500');

    // No external asset requests: no remote fonts, stylesheets, or scripts —
    // the fallback must stand on its own even when the build is unavailable.
    expect($response->getContent())
        ->not->toContain('googleapis')
        ->not->toContain('gstatic')
        ->not->toContain('<link')
        ->not->toContain('<script');
});

test('the 503 fallback renders the branded maintenance Blade page', function (): void {
    config(['app.debug' => false]);

    $response = $this->get(abortingRoute(503));

    $response->assertStatus(503)
        ->assertSee('We’re tidying the desk', false)
        ->assertSee('503');

    expect($response->getContent())
        ->not->toContain('googleapis')
        ->not->toContain('gstatic')
        ->not->toContain('<link')
        ->not->toContain('<script');
});
