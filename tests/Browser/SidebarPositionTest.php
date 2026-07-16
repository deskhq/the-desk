<?php

declare(strict_types=1);

/**
 * The sidebar-position preference re-binds the dock's edge live. Reaching the
 * Appearance page through the settings menu keeps the visit client-side (a hard
 * navigate would drop the browser session), matching SettingsNavTest.
 */
test('a user moves the dock to the right edge and it applies live', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    signInThroughBrowser($alice)
        // The dock starts on the left.
        ->assertPresent('[data-slot="sidebar"][data-side="left"]')
        ->click('@sidebar-menu-button')
        ->assertPresent('@settings-menu-item')
        // Let the dropdown settle past its open/pointer-grace window.
        ->wait(0.5)
        ->click('@settings-menu-item')
        ->assertPathContains('/settings')
        ->click('@settings-nav-appearance')
        ->assertPathContains('/settings/appearance')
        // Choosing "Right" PATCHes the preference; the redirect refreshes the
        // shared user prop, which re-binds :side with no reload.
        ->click('@sidebar-position-right')
        ->assertPresent('[data-slot="sidebar"][data-side="right"]')
        ->assertMissing('[data-slot="sidebar"][data-side="left"]');
});
