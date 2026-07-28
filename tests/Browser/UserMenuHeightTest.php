<?php

declare(strict_types=1);

/**
 * The rail's user menu is sized by the room the trigger actually leaves, not by
 * a fixed pixel cap (#998).
 *
 * The popover used to cap itself at `min(34rem, 100dvh - 2rem)`. 34rem is 544px,
 * so on any viewport taller than ~576px the cap — not the viewport — decided:
 * the menu froze at 544px and its rows started scrolling with empty screen above
 * and below the card, while on a genuinely short viewport the same card ran past
 * the bottom edge. Reka measures the space the anchor leaves as
 * `--reka-popover-content-available-height`, which is what governs now.
 */

/** The pixel cap that used to bite, so the tall-viewport case cannot pass vacuously. */
const OLD_MENU_HEIGHT_CAP = 544;

/**
 * Whether the menu is a card the screen fully contains: it has a real height,
 * and neither edge is clipped by the viewport.
 */
function userMenuStaysInsideTheViewport(): string
{
    return <<<'JS'
    (() => {
        const menu = document.querySelector('[data-test="user-menu-popover"]');

        if (menu === null) {
            return false;
        }

        const box = menu.getBoundingClientRect();

        return box.height > 0
            && box.top >= -1
            && box.bottom <= window.innerHeight + 1;
    })()
    JS;
}

/** Whether the scrolling region holds anything the viewer has to scroll to reach. */
function userMenuRowsOverflow(): string
{
    return <<<'JS'
    (() => {
        const rows = document.querySelector('[data-test="user-menu-rows"]');

        return rows !== null && rows.scrollHeight > rows.clientHeight + 1;
    })()
    JS;
}

/** Whether the given row sits fully inside both the menu card and the screen. */
function menuRowIsFullyVisible(string $selector): string
{
    return <<<JS
    (() => {
        const menu = document.querySelector('[data-test="user-menu-popover"]');
        const row = document.querySelector('[data-test="{$selector}"]');

        if (menu === null || row === null) {
            return false;
        }

        const menuBox = menu.getBoundingClientRect();
        const box = row.getBoundingClientRect();

        return box.height > 0
            && box.top >= menuBox.top - 1
            && box.bottom <= menuBox.bottom + 1
            && box.top >= -1
            && box.bottom <= window.innerHeight + 1;
    })()
    JS;
}

test('the rail menu shows every row without an inner scrollbar when the screen has room', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    $cap = OLD_MENU_HEIGHT_CAP;

    signInThroughBrowser($alice)
        ->resize(1280, 1000)
        ->click('@rail-destination-you')
        ->assertPresent('@user-menu-popover')
        // Let the popover settle past its open animation, so the box being
        // measured is the resting one rather than a zoom-in frame.
        ->wait(0.5)
        ->assertScript(userMenuStaysInsideTheViewport(), true)
        // The menu grew past the cap that used to freeze it — without this the
        // "no scrollbar" assertion below would hold for a menu that simply fits.
        ->assertScript(<<<JS
        (() => {
            const menu = document.querySelector('[data-test="user-menu-popover"]');

            return menu.getBoundingClientRect().height > {$cap};
        })()
        JS, true)
        // Nothing is hidden behind a scrollbar...
        ->assertScript(userMenuRowsOverflow(), false)
        // ...so the last two things in the menu are on screen as it opens.
        ->assertScript(menuRowIsFullyVisible('logout-button'), true)
        ->assertScript(menuRowIsFullyVisible('user-menu-version'), true);
});

test('the rail menu stays inside a short screen and scrolls its rows instead', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    signInThroughBrowser($alice)
        // Wide enough for the rail, short enough that the menu genuinely cannot
        // fit — the case the fixed cap rendered clipped by the screen edge.
        ->resize(1024, 520)
        ->click('@rail-destination-you')
        ->assertPresent('@user-menu-popover')
        ->wait(0.5)
        ->assertScript(userMenuStaysInsideTheViewport(), true)
        ->assertScript(userMenuRowsOverflow(), true)
        // Log out and the version footer are reachable by scrolling the rows...
        ->assertScript(<<<'JS'
        (() => {
            const rows = document.querySelector('[data-test="user-menu-rows"]');
            rows.scrollTop = rows.scrollHeight;

            return true;
        })()
        JS, true)
        ->assertScript(menuRowIsFullyVisible('logout-button'), true)
        ->assertScript(menuRowIsFullyVisible('user-menu-version'), true)
        // ...and the identity header did not scroll away with them.
        ->assertScript(<<<'JS'
        (() => {
            const menu = document.querySelector('[data-test="user-menu-popover"]')
                .getBoundingClientRect();
            const identity = document.querySelector('[data-test="user-menu-identity"]')
                .getBoundingClientRect();

            return Math.round(identity.top) <= Math.round(menu.top) + 1
                && identity.height > 0;
        })()
        JS, true);
});

test('the You panel takes the whole drawer rather than a height of its own', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    // Below `md` the same content fills the dock's panel, so the drawer is what
    // bounds it there. Nothing may cap it short of that: the rows run from the
    // pinned header to the foot of the panel, and scroll only past that point.
    signInThroughBrowser($alice)
        ->resize(390, 844)
        ->click('@sidebar-toggle')
        ->click('@tab-destination-you')
        ->assertPresent('@destination-panel-you')
        ->wait(0.5)
        ->assertScript(<<<'JS'
        (() => {
            const panel = document.querySelector('[data-test="destination-panel-you"]')
                .getBoundingClientRect();
            const identity = document.querySelector('[data-test="user-menu-identity"]')
                .getBoundingClientRect();
            const rows = document.querySelector('[data-test="user-menu-rows"]')
                .getBoundingClientRect();

            return Math.abs(rows.top - identity.bottom) <= 1
                && Math.abs(rows.bottom - panel.bottom) <= 1
                && rows.height > 0;
        })()
        JS, true);
});
