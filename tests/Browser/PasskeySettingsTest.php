<?php

declare(strict_types=1);

use App\Models\User;
use Pest\Browser\Api\AwaitableWebpage;

/**
 * Sign a fresh user in and land them on the Security settings page, all within
 * one browser context so the session holds. Visiting the protected page first
 * stores it as the "intended" URL, so login bounces back to it; that page is
 * behind Fortify's password-confirmation gate, so we clear the confirm screen
 * too before arriving on Security.
 */
function signInOnSecurityPageForPasskeys(User $user): AwaitableWebpage
{
    return visit('/settings/security')
        ->type('#email', $user->email)
        ->type('#password', 'password')
        ->click('@login-button')
        // The Security page requires a fresh password confirmation.
        ->assertPresent('@confirm-password-button')
        ->type('#password', 'password')
        ->click('@confirm-password-button')
        ->assertPathIs('/settings/security');
}

test('the security page surfaces passkey management when the toggle is on', function (): void {
    config(['fortify.passkeys_enabled' => true]);

    $user = User::factory()->create();

    signInOnSecurityPageForPasskeys($user)
        ->assertSee('Passkeys')
        // A WebAuthn-capable browser shows the enrolment affordance straight away.
        ->assertPresent('@add-passkey-button');
});

test('the security page hides passkeys when the toggle is off', function (): void {
    config(['fortify.passkeys_enabled' => false]);

    $user = User::factory()->create();

    signInOnSecurityPageForPasskeys($user)
        ->assertMissing('@add-passkey-button');
});
