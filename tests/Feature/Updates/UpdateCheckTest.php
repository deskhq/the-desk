<?php

use App\Models\User;
use App\Support\UpdateChecker;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Fake the GitHub "latest release" endpoint with the given tag.
 */
function fakeLatestRelease(string $tag): void
{
    Http::fake([
        'api.github.com/repos/deskhq/the-desk/releases/latest' => Http::response(['tag_name' => $tag]),
    ]);
}

beforeEach(function (): void {
    config([
        'app.version' => '1.4.2',
        'updates.enabled' => true,
        'updates.repository' => 'deskhq/the-desk',
    ]);
});

test('status reports the running version from config', function (): void {
    expect(app(UpdateChecker::class)->status()->current)->toBe('1.4.2');
});

test('a newer stable release flags an update as available', function (): void {
    fakeLatestRelease('v1.6.0');

    $this->artisan('updates:check')->assertSuccessful();

    $status = app(UpdateChecker::class)->status();

    expect($status->latest)->toBe('1.6.0')
        ->and($status->updateAvailable)->toBeTrue()
        ->and($status->notesUrl)->toBe('https://github.com/deskhq/the-desk/releases/tag/v1.6.0');
});

test('the same or an older release is not flagged as an update', function (string $tag): void {
    fakeLatestRelease($tag);

    $this->artisan('updates:check')->assertSuccessful();

    $status = app(UpdateChecker::class)->status();

    expect($status->latest)->toBe(ltrim($tag, 'v'))
        ->and($status->updateAvailable)->toBeFalse();
})->with(['v1.4.2', 'v1.4.1', 'v1.0.0']);

/*
 * Candidate builds now report their own `-rc.N` version rather than the stable
 * one they were cut from (#660), so the comparison has to read that suffix the
 * SemVer way: a candidate is *ahead* of the stable release it precedes and
 * *behind* the one it becomes.
 */
test('a candidate ahead of the latest stable is not flagged as an update', function (): void {
    config(['app.version' => '1.5.0-rc.2']);
    fakeLatestRelease('v1.4.2');

    $this->artisan('updates:check')->assertSuccessful();

    expect(app(UpdateChecker::class)->status()->updateAvailable)->toBeFalse();
});

test('a candidate is behind the stable release it becomes', function (): void {
    config(['app.version' => '1.5.0-rc.2']);
    fakeLatestRelease('v1.5.0');

    $this->artisan('updates:check')->assertSuccessful();

    expect(app(UpdateChecker::class)->status()->updateAvailable)->toBeTrue();
});

test('the check makes no outbound request when disabled', function (): void {
    config(['updates.enabled' => false]);
    Http::fake();

    $this->artisan('updates:check')->assertSuccessful();

    Http::assertNothingSent();

    $status = app(UpdateChecker::class)->status();
    expect($status->latest)->toBeNull()
        ->and($status->updateAvailable)->toBeFalse();
});

test('a disabled check ignores any previously cached result', function (): void {
    fakeLatestRelease('v1.6.0');
    $this->artisan('updates:check')->assertSuccessful();

    config(['updates.enabled' => false]);

    $status = app(UpdateChecker::class)->status();
    expect($status->latest)->toBeNull()
        ->and($status->updateAvailable)->toBeFalse();
});

test('a failed request is swallowed and keeps the last known-good result', function (): void {
    // First refresh succeeds and caches 1.6.0; the next one hits an error (GitHub
    // down / rate-limited) and must not throw nor clobber the cached latest.
    Http::fakeSequence('https://api.github.com/*')
        ->push(['tag_name' => 'v1.6.0'])
        ->pushStatus(503);

    $this->artisan('updates:check')->assertSuccessful();
    expect(app(UpdateChecker::class)->status()->latest)->toBe('1.6.0');

    $this->artisan('updates:check')->assertSuccessful();
    expect(app(UpdateChecker::class)->status()->latest)->toBe('1.6.0');
});

test('a network exception during the check is swallowed', function (): void {
    Http::fake(fn () => throw new ConnectionException('offline'));

    $this->artisan('updates:check')->assertSuccessful();

    expect(app(UpdateChecker::class)->status()->latest)->toBeNull();
});

test('a malformed tag is ignored', function (): void {
    fakeLatestRelease('not-a-version');

    $this->artisan('updates:check')->assertSuccessful();

    expect(app(UpdateChecker::class)->status()->latest)->toBeNull();
});

test('a non-string or missing tag is ignored', function (): void {
    Http::fake([
        'https://api.github.com/*' => Http::response(['tag_name' => 42]),
    ]);

    $this->artisan('updates:check')->assertSuccessful();

    expect(app(UpdateChecker::class)->status()->latest)->toBeNull();
});

test('the update status is shared to authenticated users', function (): void {
    fakeLatestRelease('v1.6.0');
    $this->artisan('updates:check')->assertSuccessful();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('locale.edit'))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('update.current', '1.4.2')
            ->where('update.latest', '1.6.0')
            ->where('update.updateAvailable', true)
        );
});

test('the update status is not shared to guests', function (): void {
    $this->get(route('home'))
        ->assertInertia(fn (Assert $page): Assert => $page->where('update', null));
});

test('the about settings page exposes the version and update-check toggle', function (): void {
    fakeLatestRelease('v1.6.0');
    $this->artisan('updates:check')->assertSuccessful();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('about.edit'))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('settings/About')
            ->where('updateCheckEnabled', true)
            ->where('update.current', '1.4.2')
            ->where('update.latest', '1.6.0')
        );
});

test('the about page reflects a disabled update check', function (): void {
    config(['updates.enabled' => false]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('about.edit'))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('settings/About')
            ->where('updateCheckEnabled', false)
        );
});
