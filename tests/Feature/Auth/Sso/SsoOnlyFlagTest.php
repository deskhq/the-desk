<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('sso-only mode disables registration when a provider is configured', function (): void {
    $this->reloadWithEnv([
        'AUTH_SSO_ONLY' => true,
        'REGISTRATION_ENABLED' => true,
        'SSO_OIDC_CLIENT_ID' => 'client-id',
        'SSO_OIDC_ISSUER' => 'https://idp.test',
    ]);

    $this->get('/register')->assertNotFound();
    $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();

    $this->assertGuest();
});

test('sso-only mode blocks password login', function (): void {
    config(['sso.enforced' => true]);
    $user = User::factory()->create(['password' => Hash::make('password')]);

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertNotFound();

    $this->assertGuest();
});

test('password login still works when sso-only mode is off', function (): void {
    config(['sso.enforced' => false]);
    $user = User::factory()->create(['password' => Hash::make('password')]);

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect();

    $this->assertAuthenticated();
});

test('sso-only mode has no effect when no provider is configured, so no one is locked out', function (): void {
    // AUTH_SSO_ONLY on but no OIDC provider: enforcement must not engage, or the
    // instance would disable every sign-in path.
    $this->reloadWithEnv(['AUTH_SSO_ONLY' => true]);

    expect(config('sso.enforced'))->toBeFalse();

    $this->get('/register')->assertOk();

    $user = User::factory()->create(['password' => Hash::make('password')]);
    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
    $this->assertAuthenticated();
});

test('the shared sso prop reflects active enforcement with a configured provider', function (): void {
    config(['sso.oidc.enabled' => true, 'sso.enforced' => true]);

    $this->get(route('login'))->assertInertia(fn (Assert $page): Assert => $page
        ->component('auth/Login')
        ->where('sso.oidcEnabled', true)
        ->where('sso.passwordLoginEnabled', false),
    );
});

test('the shared sso prop is off by default', function (): void {
    $this->get(route('login'))->assertInertia(fn (Assert $page): Assert => $page
        ->component('auth/Login')
        ->where('sso.oidcEnabled', false)
        ->where('sso.passwordLoginEnabled', true),
    );
});
