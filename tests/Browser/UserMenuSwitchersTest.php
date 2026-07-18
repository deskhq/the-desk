<?php

declare(strict_types=1);

/**
 * The user menu carries quick Theme + Sidebar switchers that reuse the same
 * composables as Settings → Appearance, so flipping either applies instantly,
 * leaves the dropdown open, and stays in sync with the settings surface.
 */
test('the theme switcher repaints live from the menu without closing it', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    signInThroughBrowser($alice)
        ->click('@sidebar-menu-button')
        ->assertPresent('@menu-theme-switcher')
        // Let the dropdown settle past its open/pointer-grace window.
        ->wait(0.5)
        // Picking Dark toggles the root `.dark` class through useAppearance...
        ->click('[data-test="menu-theme-switcher"] [aria-label="Dark"]')
        ->assertScript('document.documentElement.classList.contains("dark")', true)
        // ...and the menu stays open with the control still under the cursor.
        ->assertPresent('@menu-theme-switcher')
        ->assertAttribute(
            '[data-test="menu-theme-switcher"] [aria-label="Dark"]',
            'aria-checked',
            'true',
        );
});

test('the sidebar switcher moves the dock live from the menu without closing it', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    signInThroughBrowser($alice)
        ->assertPresent('[data-slot="sidebar"][data-side="left"]')
        ->click('@sidebar-menu-button')
        ->assertPresent('@menu-sidebar-switcher')
        ->wait(0.5)
        // Choosing Right PATCHes the preference; the redirect refreshes the shared
        // user prop, re-binding :side with no reload, and the menu stays open.
        ->click('[data-test="menu-sidebar-switcher"] [aria-label="Right"]')
        ->assertPresent('[data-slot="sidebar"][data-side="right"]')
        ->assertMissing('[data-slot="sidebar"][data-side="left"]')
        ->assertPresent('@menu-sidebar-switcher');
});

test('the menu switchers are keyboard-operable groups of named menuitemradios', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    signInThroughBrowser($alice)
        ->click('@sidebar-menu-button')
        ->assertPresent('@menu-theme-switcher')
        ->wait(0.5)
        // Each track is a named group (menuitemradio children are valid ARIA
        // inside the parent role="menu"; a radiogroup would not be).
        ->assertAriaAttribute('[data-test="menu-theme-switcher"]', 'label', 'Theme')
        ->assertAriaAttribute(
            '[data-test="menu-sidebar-switcher"]',
            'label',
            'Sidebar position',
        )
        ->assertAttribute(
            '[data-test="menu-theme-switcher"] [aria-label="Dark"]',
            'role',
            'menuitemradio',
        )
        // Arrow keys move within a group and select: focusing Light then pressing
        // ArrowRight advances to (and checks) the Dark segment.
        ->keys('[data-test="menu-theme-switcher"] [aria-label="Light"]', 'ArrowRight')
        ->assertAttribute(
            '[data-test="menu-theme-switcher"] [aria-label="Dark"]',
            'aria-checked',
            'true',
        )
        // The menu stays open throughout (the segments are not closing menu items).
        ->assertPresent('@menu-theme-switcher');
});
