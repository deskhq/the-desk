<?php

declare(strict_types=1);

use App\Enums\AppLocale;
use Pest\Browser\Api\AwaitableWebpage;

/**
 * The user menu below the `md` breakpoint (#777, design m8).
 *
 * Below `md` the user chip opens a bottom sheet carrying the same content as
 * the desktop dropdown — a dropdown anchored to a dock row inside an
 * off-canvas sheet is both hard to reach and hard to size on a phone. From
 * `md` up the dropdown is unchanged.
 */

/**
 * Open the user menu at the given viewport. Below the breakpoint the dock is a
 * Sheet, so the user chip only exists once it is opened; from `md` up the rail
 * is always mounted.
 */
function openUserMenu(AwaitableWebpage $page, int $width, int $height): AwaitableWebpage
{
    $page = $page->resize($width, $height);

    if ($width < 768) {
        $page = $page->click('@sidebar-toggle');
    }

    return $page->click('@sidebar-menu-button');
}

/**
 * Whether the open user-menu sheet presents as a bottom sheet: pinned to the
 * bottom edge, spanning the full width, and no taller than the screen.
 */
function userMenuSurfaceIsASheet(): string
{
    return <<<'JS'
    (() => {
        const sheet = document.querySelector('[data-test="user-menu-sheet"]');

        if (sheet === null) {
            return false;
        }

        const box = sheet.getBoundingClientRect();

        return Math.round(box.bottom) === window.innerHeight
            && Math.round(box.width) === window.innerWidth
            && box.top >= 0
            && box.height <= window.innerHeight;
    })()
    JS;
}

test('the user menu is a bottom sheet on a phone and the dropdown from md up', function (int $width, int $height, bool $expectSheet): void {
    ['owner' => $alice] = browserTeamWithChannel();

    $page = openUserMenu(signInThroughBrowser($alice), $width, $height)
        ->assertPresent('@settings-menu-item');

    if ($expectSheet) {
        $page->assertScript(userMenuSurfaceIsASheet(), true);
    } else {
        $page->assertNotPresent('@user-menu-sheet')
            ->assertPresent('[data-slot="dropdown-menu-content"]');
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

test('the keyboard-shortcuts row is dropped below md and kept above it', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    // A phone has no hardware keyboard, so the row would be dead weight —
    // called out on the m8 mockup.
    $page = openUserMenu(signInThroughBrowser($alice), 390, 844)
        ->assertPresent('@replay-tour-menu-item')
        ->assertNotPresent('@keyboard-shortcuts-menu-item')
        // The sidebar-position switcher is a desktop affordance too: below
        // `md` the dock is an overlay drawer, not a positioned pane.
        ->assertNotPresent('@menu-sidebar-switcher');

    // From `md` up the dropdown still carries both rows.
    $page->resize(1280, 800)
        ->click('@sidebar-menu-button')
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
        // The card degrades to the plain affordance in place, sheet still up.
        ->assertPresent('@set-status-menu-item')
        ->assertNotPresent('@edit-status-menu-item')
        ->assertScript(userMenuSurfaceIsASheet(), true);

    expect($alice->refresh()->status_text)->toBeNull()
        ->and($alice->status_emoji)->toBeNull();

    // The plain row trades the sheet for the status dialog.
    $page->click('@set-status-menu-item')
        ->assertPresent('@status-dialog')
        ->assertNotPresent('@user-menu-sheet');
});

test('pause notifications opens the presets as a second sheet and returns cleanly', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    $page = openUserMenu(signInThroughBrowser($alice), 390, 844)
        ->assertNotPresent('@pause-notifications-submenu')
        ->click('@pause-notifications-menu-item')
        ->assertPresent('@pause-notifications-submenu')
        // The presets present as their own bottom sheet, not a nested flyout.
        ->assertScript(<<<'JS'
        (() => {
            const sheet = document.querySelector('[data-test="pause-notifications-submenu"]');
            const box = sheet.getBoundingClientRect();

            return Math.round(box.bottom) === window.innerHeight
                && Math.round(box.width) === window.innerWidth;
        })()
        JS, true)
        ->click('@pause-preset-thirty-minutes')
        // Choosing a preset retreats to the menu sheet, where the paused card
        // has grown in place — immediate proof the pause took.
        ->assertNotPresent('@pause-notifications-submenu')
        ->assertPresent('@dnd-paused-card')
        ->assertScript(userMenuSurfaceIsASheet(), true);

    expect($alice->refresh()->dnd_until)->not->toBeNull();

    // The card's Resume pill lifts the pause in place.
    $page->click('@dnd-resume-menu-item')
        ->assertNotPresent('@dnd-paused-card')
        ->assertScript(userMenuSurfaceIsASheet(), true);

    expect($alice->refresh()->dnd_until)->toBeNull();
});

test('the theme control switches the theme from the sheet without closing it', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    openUserMenu(signInThroughBrowser($alice), 390, 844)
        ->assertPresent('@menu-theme-switcher')
        // Outside the dropdown's role="menu" the control is a plain radiogroup.
        ->assertAttribute('[data-test="menu-theme-switcher"]', 'role', 'radiogroup')
        ->click('[data-test="menu-theme-switcher"] [aria-label="Dark"]')
        ->assertScript('document.documentElement.classList.contains("dark")', true)
        ->assertAttribute(
            '[data-test="menu-theme-switcher"] [aria-label="Dark"]',
            'aria-checked',
            'true',
        )
        ->assertScript(userMenuSurfaceIsASheet(), true);
});

test('logging out from the sheet signs the user out', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    openUserMenu(signInThroughBrowser($alice), 390, 844)
        ->assertPresent('@logout-button')
        ->click('@logout-button')
        ->assertPathIs('/login');
});

test('every sheet row is at least a 44px hit target', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    $rows = json_encode([
        'set-status-menu-item',
        'toggle-presence-menu-item',
        'pause-notifications-menu-item',
        'settings-menu-item',
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

test('the sheet scrolls internally on a short viewport and stays inside it', function (int $width, int $height): void {
    ['owner' => $alice] = browserTeamWithChannel();

    openUserMenu(signInThroughBrowser($alice), $width, $height)
        ->assertScript(userMenuSurfaceIsASheet(), true)
        // Every row is reachable by scrolling the sheet itself — the version
        // line at the very bottom can be brought fully into view.
        ->assertScript(<<<'JS'
        (() => {
            const sheet = document.querySelector('[data-test="user-menu-sheet"]');
            sheet.scrollTop = sheet.scrollHeight;

            const version = document.querySelector('[data-test="user-menu-version"]')
                .getBoundingClientRect();

            return version.top >= 0 && version.bottom <= window.innerHeight + 1;
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

test('the open user-menu sheet has no serious accessibility violations in either theme', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    // The sheet re-homes menu semantics into a dialog — plain buttons instead
    // of menu items, a standalone radiogroup instead of menuitemradios — and
    // the automated gate does not audit a11y. Settle first: the sheet slides
    // in over 200ms and `assertNoAccessibilityIssues` does not retry, so axe
    // sampled mid-fade reads composited half-transparent text.
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

test('the sheet keeps its copy inside the tightest viewport in French', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();
    $alice->update(['locale' => AppLocale::French]);

    openUserMenu(signInThroughBrowser($alice), 360, 740)
        ->assertSee('Pause des notifications')
        ->assertSee('Définir un statut')
        ->assertSee('Se marquer absent')
        ->assertScript(userMenuSurfaceIsASheet(), true)
        // Longer French strings truncate instead of pushing the sheet wide.
        ->assertScript(<<<'JS'
        (() => {
            const sheet = document.querySelector('[data-test="user-menu-sheet"]');

            return sheet.scrollWidth <= sheet.clientWidth;
        })()
        JS, true);
});
