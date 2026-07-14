<?php

declare(strict_types=1);

test('logging out renders the login page immediately without a manual refresh', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    $page = signInThroughBrowser($alice);
    $page->assertPresent('@message-composer-input');

    // Open the user menu and log out. Logout answers with a full-page navigation
    // (Inertia::location) to the login screen, so the authenticated shell tears
    // down and the login form renders in the same flow — no manual refresh, and
    // the URL and rendered page stay in sync.
    $page
        ->click('@sidebar-menu-button')
        ->click('@logout-button')
        ->assertPathIs('/login')
        ->assertPresent('@login-button')
        ->assertMissing('@message-composer-input');
});
