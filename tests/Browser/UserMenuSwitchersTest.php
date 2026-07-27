<?php

declare(strict_types=1);

/**
 * The user menu carries quick Theme + Sidebar switchers that reuse the same
 * composables as Settings → Appearance, so flipping either applies instantly,
 * leaves the popover open, and stays in sync with the settings surface.
 */
test('the theme switcher repaints live from the menu without closing it', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    signInThroughBrowser($alice)
        ->click('@rail-destination-you')
        ->assertPresent('@menu-theme-switcher')
        // Let the popover settle past its open/pointer-grace window.
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
        ->click('@rail-destination-you')
        ->assertPresent('@menu-sidebar-switcher')
        ->wait(0.5)
        // Choosing Right PATCHes the preference; the redirect refreshes the shared
        // user prop, re-binding :side with no reload, and the menu stays open.
        ->click('[data-test="menu-sidebar-switcher"] [aria-label="Right"]')
        ->assertPresent('[data-slot="sidebar"][data-side="right"]')
        ->assertMissing('[data-slot="sidebar"][data-side="left"]')
        ->assertPresent('@menu-sidebar-switcher');
});

test('the menu switchers are keyboard-operable radiogroups with named options', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    signInThroughBrowser($alice)
        ->click('@rail-destination-you')
        ->assertPresent('@menu-theme-switcher')
        ->wait(0.5)
        // Each track is a named radiogroup. The menu is a popover rather than a
        // `role="menu"` since #942, so `menuitemradio` children — only valid
        // ARIA inside a menu — would be the wrong pattern here.
        ->assertAriaAttribute('[data-test="menu-theme-switcher"]', 'label', 'Theme')
        ->assertAriaAttribute(
            '[data-test="menu-sidebar-switcher"]',
            'label',
            'Sidebar position',
        )
        ->assertAttribute('[data-test="menu-theme-switcher"]', 'role', 'radiogroup')
        ->assertAttribute(
            '[data-test="menu-theme-switcher"] [aria-label="Dark"]',
            'role',
            'radio',
        )
        // Arrow keys move within a group and select: focusing Light then pressing
        // ArrowRight advances to (and checks) the Dark segment.
        ->keys('[data-test="menu-theme-switcher"] [aria-label="Light"]', 'ArrowRight')
        ->assertAttribute(
            '[data-test="menu-theme-switcher"] [aria-label="Dark"]',
            'aria-checked',
            'true',
        )
        // The popover stays open throughout: the segments apply in place.
        ->assertPresent('@menu-theme-switcher');
});
