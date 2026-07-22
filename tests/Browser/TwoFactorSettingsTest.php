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
function signInOnSecurityPage(User $user): AwaitableWebpage
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

test('a user can start two-factor enrolment from the security page', function (): void {
    config(['fortify.two_factor_enabled' => true]);

    $user = User::factory()->create();

    signInOnSecurityPage($user)
        ->assertSee('Two-factor authentication')
        // Password was just confirmed, so enrolment starts straight away and the
        // setup screen renders with its QR code and recovery codes.
        ->click('@enable-two-factor-button')
        ->assertPresent('@two-factor-setup')
        ->assertPresent('@two-factor-qr')
        // The QR is server-rendered SVG sanitized against the `qrCode` allowlist
        // on its way through `<SafeHtml>`; assert the scannable markup itself
        // survives, not just the box it sits in — an allowlist that dropped
        // `<path>` would leave an empty container that still passes the check above.
        ->assertPresent('[data-test="two-factor-qr"] svg path')
        ->assertPresent('@two-factor-recovery-codes')
        ->assertPresent('@confirm-two-factor-button');
});

test('the security page hides two-factor when the toggle is off', function (): void {
    config(['fortify.two_factor_enabled' => false]);

    $user = User::factory()->create();

    signInOnSecurityPage($user)
        ->assertMissing('@enable-two-factor-button');
});
