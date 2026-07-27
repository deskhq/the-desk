<?php

declare(strict_types=1);

use App\Enums\AppLocale;
use Pest\Browser\Api\AwaitableWebpage;

/**
 * The "You" destination (#942, design "Vous").
 *
 * One menu, two surfaces: below `md` it is the fifth tab of the dock's tab bar,
 * filling the panel; from `md` up the rail's own avatar opens it as a popover
 * and leaves the panel on whatever destination it was already showing. The
 * bottom sheet these tests used to drive, and the dock-footer chip that opened
 * it, are both gone.
 */

/**
 * Open the "You" destination at the given viewport. Below the breakpoint the
 * dock is a Sheet, so the tab bar only exists once the drawer is open; from
 * `md` up the rail is always mounted.
 */
function openUserMenu(AwaitableWebpage $page, int $width, int $height): AwaitableWebpage
{
    $page = $page->resize($width, $height);

    if ($width < 768) {
        return $page->click('@sidebar-toggle')
            ->click('@tab-destination-you');
    }

    return $page->click('@rail-destination-you');
}

/**
 * Whether the open panel fills the dock's drawer: it starts under the header,
 * runs down to the tab bar that opened it, and stays inside the screen.
 */
function userPanelFillsTheDrawer(): string
{
    return <<<'JS'
    (() => {
        const panel = document.querySelector('[data-test="destination-panel-you"]');
        const tabs = document.querySelector('[data-test="navigation-tab-bar"]');
        const header = document.querySelector('[data-test="workspace-switcher"]');

        if (panel === null || tabs === null || header === null) {
            return false;
        }

        const box = panel.getBoundingClientRect();
        const tabsBox = tabs.getBoundingClientRect();
        const headerBox = header.getBoundingClientRect();

        // Bounded on both ends, not merely on screen: the panel takes the room
        // between the workspace header and the tab bar, and neither overlaps it.
        return Math.round(box.top) >= Math.round(headerBox.bottom) - 1
            && box.width > 0
            && Math.round(box.bottom) <= Math.round(tabsBox.top) + 1
            && Math.round(tabsBox.bottom) <= window.innerHeight;
    })()
    JS;
}

test('the You destination is a full panel on a phone and a rail popover from md up', function (int $width, int $height, bool $expectPanel): void {
    ['owner' => $alice] = browserTeamWithChannel();

    $page = openUserMenu(signInThroughBrowser($alice), $width, $height)
        ->assertPresent('@settings-menu-item');

    if ($expectPanel) {
        $page->assertNotPresent('@user-menu-popover')
            ->assertScript(userPanelFillsTheDrawer(), true);
    } else {
        $page->assertNotPresent('@destination-panel-you')
            ->assertPresent('@user-menu-popover');
    }
})->with([
    'small phone' => [360, 740, true],
    'iPhone SE' => [375, 667, true],
    'iPhone 14' => [390, 844, true],
    'large phone' => [430, 932, true],
    // A short viewport is its own failure mode for a fixed-height surface.
    'landscape phone' => [740, 360, true],
    // The breakpoint itself is desktop, matching `useIsMobile` and Tailwind's `md:`.
    'tablet portrait' => [768, 1024, false],
    'desktop' => [1280, 800, false],
]);

test('the rail popover leaves the open destination and the URL alone', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    // The design keeps the conversation list live behind the popover, so unlike
    // the four glyph destinations "You" pins nothing on the URL. Settle the
    // param rather than reading it once: a wrong implementation writes it a tick
    // later, which a one-shot read would sail straight past (#947).
    signInThroughBrowser($alice)
        ->resize(1280, 800)
        ->navigate(browserChannelUrl($team, $channel))
        ->click('@rail-destination-you')
        ->assertPresent('@user-menu-popover')
        ->assertPresent('@channels-nav')
        ->assertScript(navParamSettles(null), true)
        ->assertQueryStringMissing('nav');
});

test('the keyboard-shortcuts and sidebar rows are dropped on the panel and kept in the popover', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    // A phone has no hardware keyboard, and below `md` the dock is an overlay
    // drawer rather than a positioned pane — both rows would be dead weight
    // there (design m8).
    $page = openUserMenu(signInThroughBrowser($alice), 390, 844)
        ->assertPresent('@replay-tour-menu-item')
        ->assertNotPresent('@keyboard-shortcuts-menu-item')
        ->assertNotPresent('@menu-sidebar-switcher');

    openUserMenu($page, 1280, 800)
        ->assertPresent('@keyboard-shortcuts-menu-item')
        ->assertPresent('@menu-sidebar-switcher');
});

test('the status card shows the active status and its clear button clears it in place', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();
    $alice->forceFill([
        'status_emoji' => '🎧',
        'status_text' => 'Heads down',
        'status_expires_at' => now()->addHours(2),
    ])->save();

    $page = openUserMenu(signInThroughBrowser($alice), 390, 844)
        ->assertPresent('@edit-status-menu-item')
        ->assertNotPresent('@set-status-menu-item')
        ->assertSee('Heads down')
        ->click('@clear-status-menu-item')
        // The card degrades to the plain affordance in place, panel still up.
        ->assertPresent('@set-status-menu-item')
        ->assertNotPresent('@edit-status-menu-item')
        ->assertScript(userPanelFillsTheDrawer(), true);

    expect($alice->refresh()->status_text)->toBeNull()
        ->and($alice->status_emoji)->toBeNull();

    // The plain row opens the status dialog over the panel.
    $page->click('@set-status-menu-item')
        ->assertPresent('@status-dialog');
});

test('pause notifications discloses the presets under the row and applies one in place', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    $page = openUserMenu(signInThroughBrowser($alice), 390, 844)
        ->assertNotPresent('@pause-notifications-submenu')
        ->click('@pause-notifications-menu-item')
        ->assertPresent('@pause-notifications-submenu')
        // The presets belong to the panel rather than to a second layer over
        // it, so they sit inside the panel's own box.
        ->assertScript(<<<'JS'
        (() => {
            const panel = document.querySelector('[data-test="destination-panel-you"]')
                .getBoundingClientRect();
            const presets = document.querySelector('[data-test="pause-notifications-submenu"]')
                .getBoundingClientRect();

            return presets.left >= panel.left - 1 && presets.right <= panel.right + 1;
        })()
        JS, true)
        ->click('@pause-preset-thirty-minutes')
        // Choosing a preset folds the disclosure back up, and the paused card
        // has grown in place — immediate proof the pause took.
        ->assertNotPresent('@pause-notifications-submenu')
        ->assertPresent('@dnd-paused-card')
        ->assertScript(userPanelFillsTheDrawer(), true);

    expect($alice->refresh()->dnd_until)->not->toBeNull();

    // The card's Resume pill lifts the pause in place.
    $page->click('@dnd-resume-menu-item')
        ->assertNotPresent('@dnd-paused-card')
        ->assertScript(userPanelFillsTheDrawer(), true);

    expect($alice->refresh()->dnd_until)->toBeNull();
});

test('the theme control switches the theme from the panel without leaving it', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    openUserMenu(signInThroughBrowser($alice), 390, 844)
        ->assertPresent('@menu-theme-switcher')
        // Outside a role="menu" the control is a plain radiogroup.
        ->assertAttribute('[data-test="menu-theme-switcher"]', 'role', 'radiogroup')
        ->click('[data-test="menu-theme-switcher"] [aria-label="Dark"]')
        ->assertScript('document.documentElement.classList.contains("dark")', true)
        ->assertAttribute(
            '[data-test="menu-theme-switcher"] [aria-label="Dark"]',
            'aria-checked',
            'true',
        )
        ->assertScript(userPanelFillsTheDrawer(), true);
});

test('the two rows the design adds reach the surfaces they promise', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    // "Switch workspace" is a fourth anchor onto the one workspace sheet rather
    // than a switcher of its own; "Security & devices" deep-links into the
    // settings page that already holds sessions, passkeys and two-factor.
    $page = openUserMenu(signInThroughBrowser($alice), 390, 844)
        ->assertNotPresent('@workspace-sheet')
        ->click('@switch-workspace-menu-item')
        ->assertPresent('@workspace-sheet')
        ->assertPresent('@team-switcher-item')
        ->keys('[data-test="workspace-sheet"]', 'Escape')
        ->assertNotPresent('@workspace-sheet');

    // Security is behind Fortify's password-confirmation gate, so clearing that
    // is part of arriving — the row is a deep link into the existing page, not
    // a new surface with rules of its own.
    $page->click('@security-menu-item')
        ->assertPresent('@confirm-password-button')
        ->type('#password', 'password')
        ->click('@confirm-password-button')
        ->assertPathIs('/settings/security');
});

test('logging out from the panel signs the user out', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    openUserMenu(signInThroughBrowser($alice), 390, 844)
        ->assertPresent('@logout-button')
        ->click('@logout-button')
        ->assertPathIs('/login');
});

test('every panel row is at least a 44px hit target', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    $rows = json_encode([
        'set-status-menu-item',
        'toggle-presence-menu-item',
        'pause-notifications-menu-item',
        'settings-menu-item',
        'switch-workspace-menu-item',
        'security-menu-item',
        'replay-tour-menu-item',
        'logout-button',
    ], JSON_THROW_ON_ERROR);

    openUserMenu(signInThroughBrowser($alice), 390, 844)
        ->assertScript(<<<JS
        (() => {
            return {$rows}
                .map(name => document.querySelector('[data-test="' + name + '"]'))
                .every(row => row !== null && row.getBoundingClientRect().height >= 44);
        })()
        JS, true);
});

test('the rows that only appear in a state of their own hold the same hit target', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();
    $alice->forceFill([
        'status_emoji' => '🎧',
        'status_text' => 'Heads down',
        'status_expires_at' => now()->addHours(2),
    ])->save();

    // The status card and the disclosed presets never render beside the plain
    // rows above, so the check they owe has to be arranged for.
    $rows = json_encode([
        'edit-status-menu-item',
        'pause-preset-thirty-minutes',
        'pause-preset-custom',
        'quiet-hours-menu-item',
    ], JSON_THROW_ON_ERROR);

    openUserMenu(signInThroughBrowser($alice), 390, 844)
        ->click('@pause-notifications-menu-item')
        ->assertPresent('@pause-notifications-submenu')
        ->assertScript(<<<JS
        (() => {
            return {$rows}
                .map(name => document.querySelector('[data-test="' + name + '"]'))
                .every(row => row !== null && row.getBoundingClientRect().height >= 44);
        })()
        JS, true);
});

test('the panel scrolls internally on a short viewport and stays inside it', function (int $width, int $height): void {
    ['owner' => $alice] = browserTeamWithChannel();

    openUserMenu(signInThroughBrowser($alice), $width, $height)
        ->assertScript(userPanelFillsTheDrawer(), true)
        // Every row is reachable by scrolling the panel itself — the version
        // line at the very bottom can be brought fully into view.
        ->assertScript(<<<'JS'
        (() => {
            const version = document.querySelector('[data-test="user-menu-version"]');
            const scroller = version.parentElement;
            scroller.scrollTop = scroller.scrollHeight;

            const box = version.getBoundingClientRect();

            return box.top >= 0 && box.bottom <= window.innerHeight + 1;
        })()
        JS, true)
        // ...and the page behind stays locked.
        ->assertScript(<<<'JS'
        (() => document.documentElement.scrollHeight <= document.documentElement.clientHeight)()
        JS, true);
})->with([
    'iPhone SE' => [375, 667],
    'landscape phone' => [740, 360],
]);

test('the open You panel has no serious accessibility violations in either theme', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    // The panel re-homes what used to be menu semantics into plain buttons and
    // a standalone radiogroup, and the automated gate does not audit a11y.
    // Settle first: the drawer slides in over 200ms and
    // `assertNoAccessibilityIssues` does not retry, so axe sampled mid-fade
    // reads composited half-transparent text.
    $page = openUserMenu(signInThroughBrowser($alice), 390, 844)
        ->wait(0.5)
        ->assertNoAccessibilityIssues();

    $page->script(<<<'JS'
    () => {
        localStorage.setItem('appearance', 'dark');
        document.documentElement.classList.add('dark');
        document.documentElement.style.colorScheme = 'dark';
    }
    JS);

    $page->wait(0.5)->assertNoAccessibilityIssues();
});

test('the panel keeps its copy inside the tightest viewport in French', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();
    $alice->update(['locale' => AppLocale::French]);

    openUserMenu(signInThroughBrowser($alice), 360, 740)
        ->assertSee('Pause des notifications')
        ->assertSee('Définir un statut')
        ->assertSee('Se marquer absent')
        ->assertSee('Changer d’espace')
        ->assertScript(userPanelFillsTheDrawer(), true)
        // Longer French strings truncate instead of pushing the panel wide.
        ->assertScript(<<<'JS'
        (() => {
            const panel = document.querySelector('[data-test="destination-panel-you"]');

            return panel.scrollWidth <= panel.clientWidth;
        })()
        JS, true);
});
