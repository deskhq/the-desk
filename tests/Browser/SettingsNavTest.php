<?php

declare(strict_types=1);

use App\Models\User;
use Pest\Browser\Api\Webpage;

/**
 * Open Settings from the workspace user menu (a client-side Inertia visit, so the
 * browser session survives), gating each step before asserting on the sidebar.
 */
function visitSettings(User $user): Webpage
{
    return signInThroughBrowser($user)
        ->click('@sidebar-menu-button')
        ->assertPresent('@settings-menu-item')
        // Let the dropdown settle past its open/pointer-grace window, otherwise
        // the item click can be swallowed and never navigate.
        ->wait(0.5)
        ->click('@settings-menu-item')
        ->assertPathContains('/settings');
}

test('an admin reaches the team-evidence group from the settings sidebar', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    visitSettings($alice)
        ->assertPresent('[data-test="settings-nav-audit-log"]')
        ->assertPresent('[data-test="settings-nav-security-log"]')
        ->assertPresent('[data-test="settings-nav-exports"]');
});

test('a plain member never sees the team-evidence group', function (): void {
    ['member' => $bob] = browserTeamWithChannel();

    visitSettings($bob)
        // The personal Settings items still render for everyone...
        ->assertPresent('[data-test="settings-nav-profile"]')
        // ...but none of the admin-only evidence surfaces do.
        ->assertMissing('[data-test="settings-nav-audit-log"]')
        ->assertMissing('[data-test="settings-nav-security-log"]')
        ->assertMissing('[data-test="settings-nav-exports"]');
});
