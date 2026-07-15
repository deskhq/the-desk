<?php

use App\Enums\SecurityEventType;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Laravel\Passkeys\Passkey;

/**
 * Persist a passkey for the user with a throwaway credential, standing in for a
 * completed WebAuthn registration ceremony (the cryptographic exchange itself is
 * covered by the laravel/passkeys package).
 */
function registerPasskeyFor(User $user, string $name = 'MacBook Pro'): Passkey
{
    return $user->passkeys()->create([
        'name' => $name,
        'credential_id' => Str::random(40),
        'credential' => ['aaguid' => '00000000-0000-0000-0000-000000000000'],
    ]);
}

test('the passkey feature is registered regardless of the toggle', function (): void {
    // Registered unconditionally so Wayfinder always emits the passkey route
    // modules and the frontend build stays stable when the toggle is off — route
    // registration is independent of the runtime availability flag.
    config(['fortify.passkeys_enabled' => false]);

    expect(Features::enabled(Features::passkeys()))->toBeTrue();
    expect(Route::has('passkey.login'))->toBeTrue();
    expect(Route::has('passkey.store'))->toBeTrue();
    expect(Route::has('passkey.destroy'))->toBeTrue();
});

test('the security page offers passkey management when the toggle is on', function (): void {
    config(['fortify.passkeys_enabled' => true, 'sso.enforced' => false]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('settings/Security')
            ->where('canManagePasskeys', true)
            ->has('passkeys', 0),
        );
});

test('the security page hides passkey management when the toggle is off', function (): void {
    config(['fortify.passkeys_enabled' => false]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('settings/Security')
            ->where('canManagePasskeys', false)
            ->missing('passkeys'),
        );
});

test('the security page hides passkey management under SSO enforcement', function (): void {
    // Under AUTH_SSO_ONLY the identity provider owns authentication, so
    // app-native passkeys are moot even with the toggle on.
    config(['fortify.passkeys_enabled' => true, 'sso.enforced' => true]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('canManagePasskeys', false)
            ->missing('passkeys'),
        );
});

test('the security page lists the user\'s registered passkeys newest first', function (): void {
    config(['fortify.passkeys_enabled' => true]);

    $user = User::factory()->create();
    $older = registerPasskeyFor($user, 'Work Laptop');
    $older->forceFill(['created_at' => now()->subDay(), 'last_used_at' => now()->subHour()])->save();
    $newer = registerPasskeyFor($user, 'Phone');

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('passkeys', 2)
            ->where('passkeys.0.id', (string) $newer->id)
            ->where('passkeys.0.name', 'Phone')
            ->where('passkeys.0.authenticator', null)
            ->where('passkeys.0.lastUsedAt', null)
            ->has('passkeys.0.createdAt')
            ->where('passkeys.1.id', (string) $older->id)
            ->whereNot('passkeys.1.lastUsedAt', null),
        );
});

test('a passkey with a recognised authenticator surfaces its device label', function (): void {
    config(['fortify.passkeys_enabled' => true]);

    $user = User::factory()->create();
    // A known AAGUID resolves to a friendly device name via the package's map.
    $user->passkeys()->create([
        'name' => 'Work key',
        'credential_id' => Str::random(40),
        'credential' => ['aaguid' => 'b5397666-4885-aa6b-cebf-e52262a439a2'],
    ]);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('passkeys.0.authenticator', 'Chromium Browser'),
        );
});

test('passkey management requires a fresh password confirmation', function (): void {
    config(['fortify.passkeys_enabled' => true]);

    $user = User::factory()->create();

    // The management endpoints carry Fortify's password.confirm middleware, so a
    // session without a fresh confirmation is bounced to the confirm screen.
    $this->actingAs($user)
        ->get(route('passkey.registration-options'))
        ->assertRedirect(route('password.confirm'));
});

test('registering a passkey records a security event', function (): void {
    $user = User::factory()->create();
    $passkey = registerPasskeyFor($user);

    event(new PasskeyRegistered($user, $passkey));

    expect($user->securityEvents()->pluck('type'))
        ->toContain(SecurityEventType::PasskeyRegistered);
});

test('removing a passkey records a security event', function (): void {
    $user = User::factory()->create();
    $passkey = registerPasskeyFor($user);

    event(new PasskeyDeleted($user, $passkey));

    expect($user->securityEvents()->pluck('type'))
        ->toContain(SecurityEventType::PasskeyRemoved);
});

test('the passkey security events surface in the activity log with their labels', function (): void {
    config(['fortify.passkeys_enabled' => true]);

    $user = User::factory()->create();
    $passkey = registerPasskeyFor($user);

    event(new PasskeyRegistered($user, $passkey));
    event(new PasskeyDeleted($user, $passkey));

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('securityEvents', fn ($events): bool => collect($events)
                ->pluck('label')
                ->intersect(['Passkey added', 'Passkey removed'])
                ->count() === 2),
        );
});

test('guests can retrieve passkey login options for passwordless sign-in', function (): void {
    // The passwordless entry point: the login screen fetches these options
    // before invoking the browser's WebAuthn prompt.
    config(['fortify.passkeys_enabled' => true, 'sso.enforced' => false]);

    $this->getJson(route('passkey.login-options'))
        ->assertOk()
        ->assertJsonStructure(['options']);
});

test('passkey endpoints are blocked when the toggle is off', function (): void {
    // The routes stay registered (for a stable frontend build) but the
    // middleware short-circuits them so the disabled feature is truly off — the
    // guest login ceremony and the authenticated management endpoints alike.
    config(['fortify.passkeys_enabled' => false]);

    $this->getJson(route('passkey.login-options'))->assertNotFound();

    $user = User::factory()->create();
    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->getJson(route('passkey.registration-options'))
        ->assertNotFound();
});

test('passkey login is blocked under SSO enforcement even with the toggle on', function (): void {
    // Passkey login is an unauthenticated entry point, so leaving it open under
    // AUTH_SSO_ONLY would bypass the mandatory identity provider.
    config(['fortify.passkeys_enabled' => true, 'sso.enforced' => true]);

    $this->getJson(route('passkey.login-options'))->assertNotFound();
    $this->postJson(route('passkey.login'))->assertNotFound();
});

test('the login page exposes passkey sign-in when enabled', function (): void {
    config(['fortify.passkeys_enabled' => true, 'sso.enforced' => false]);

    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('auth/Login')
            ->where('canLoginWithPasskey', true),
        );
});

test('the login page hides passkey sign-in when the toggle is off', function (): void {
    config(['fortify.passkeys_enabled' => false]);

    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('canLoginWithPasskey', false),
        );
});

test('the login page hides passkey sign-in under SSO enforcement', function (): void {
    config(['fortify.passkeys_enabled' => true, 'sso.enforced' => true]);

    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('canLoginWithPasskey', false),
        );
});
