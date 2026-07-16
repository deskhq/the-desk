<?php

declare(strict_types=1);

/**
 * Open Settings from the workspace user menu (a client-side Inertia visit, so the
 * browser session survives), gating each step before asserting on the sidebar.
 * Inlined per test — the fluent chain's runtime type is not worth pinning behind
 * a helper (matching the settings navigation in A11yShellTest).
 */
test('an admin reaches the team-evidence group from the settings sidebar', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    signInThroughBrowser($alice)
        ->click('@sidebar-menu-button')
        ->assertPresent('@settings-menu-item')
        // Let the dropdown settle past its open/pointer-grace window, otherwise
        // the item click can be swallowed and never navigate.
        ->wait(0.5)
        ->click('@settings-menu-item')
        ->assertPathContains('/settings')
        ->assertPresent('[data-test="settings-nav-audit-log"]')
        ->assertPresent('[data-test="settings-nav-security-log"]')
        ->assertPresent('[data-test="settings-nav-exports"]');
});

test('a plain member never sees the team-evidence group', function (): void {
    ['member' => $bob] = browserTeamWithChannel();

    signInThroughBrowser($bob)
        ->click('@sidebar-menu-button')
        ->assertPresent('@settings-menu-item')
        ->wait(0.5)
        ->click('@settings-menu-item')
        ->assertPathContains('/settings')
        // The personal Settings items still render for everyone...
        ->assertPresent('[data-test="settings-nav-profile"]')
        // ...but none of the admin-only evidence surfaces do.
        ->assertMissing('[data-test="settings-nav-audit-log"]')
        ->assertMissing('[data-test="settings-nav-security-log"]')
        ->assertMissing('[data-test="settings-nav-exports"]');
});
