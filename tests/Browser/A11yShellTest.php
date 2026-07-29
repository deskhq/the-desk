<?php

declare(strict_types=1);

use App\Actions\Channels\JoinChannel;
use App\Actions\Channels\OpenDirectMessage;
use App\Models\Channel;
use App\Models\Message;

test('the toast live region is mounted in the authenticated shell', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    // Sonner renders its aria-live notification region only when <Toaster/> is
    // mounted; its presence proves toasts (and their status announcements) work.
    signInThroughBrowser($alice)
        ->assertPresent('[data-sonner-toaster]');
});

test('the sidebar "New message" action is a keyboard-operable button', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    signInThroughBrowser($alice)
        // A non-focusable <div> could never satisfy a `button[...]` match, so this
        // pins the element to a real, Tab-focusable, Enter/Space-activatable button.
        ->assertPresent('button[data-test="new-dm-trigger"]')
        // And activating it from the keyboard opens the New Direct Message modal.
        ->keys('@new-dm-trigger', 'Enter')
        ->assertPresent('@new-dm-input');
});

test('the New Direct Message palette has no serious accessibility violations, light or dark', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    // The settle lets the dialog's fade finish: axe blends the mid-animation
    // opacity into its contrast arithmetic otherwise (#775).
    $page = signInThroughBrowser($alice)
        ->keys('@new-dm-trigger', 'Enter')
        ->assertVisible('@new-dm-input')
        ->wait(0.5)
        ->assertNoAccessibilityIssues();

    switchToDarkTheme($page)
        ->assertNoAccessibilityIssues();
});

test('the sidebar "Create channel" action is a keyboard-operable button', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    signInThroughBrowser($alice)
        ->assertPresent('button[data-test="create-channel-trigger"]');
});

test('the channel timeline exposes a polite live region for inbound messages', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    signInThroughBrowser($alice)
        ->assertPresent('[data-test="message-announcer"][aria-live="polite"]');
});

test('the shell exposes a skip link targeting a focusable main region', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    signInThroughBrowser($alice)
        // A visually-hidden-until-focused link jumps past the sidebar to the
        // main content, targeting a focusable <main id="main">.
        ->assertAttribute('a[data-test="skip-to-content"]', 'href', '#main')
        ->assertAttribute('main#main', 'tabindex', '-1')
        // It is the very first focusable element in the DOM, so a keyboard user
        // reaches it before tabbing through the sidebar.
        ->assertScript(
            "document.querySelector('a[href], button, input, textarea, select')?.getAttribute('data-test')",
            'skip-to-content',
        );
});

test('an open overlay takes the shell out of the tab order and hands it back on close', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    $page = signInThroughBrowser($alice)
        ->click('@rail-destination-you')
        ->assertPresent('@settings-menu-item')
        ->wait(0.5)
        // The menu hides the shell from assistive tech, so the skip link has to
        // leave the tab order with it rather than stay focusable inside an
        // `aria-hidden` region (#730).
        ->assertScript(
            'document.querySelector(\'a[data-test="skip-to-content"]\').closest("[inert]") !== null',
        );

    $page->keys('[data-test="settings-menu-item"]', 'Escape')
        ->wait(0.5)
        ->assertNotPresent('@settings-menu-item')
        // Closing restores both halves: the skip link is reachable again, and
        // focus lands back on the trigger it left from.
        ->assertScript(
            'document.querySelector(\'a[data-test="skip-to-content"]\').closest("[inert]") === null',
        )
        ->assertScript(
            'document.activeElement?.getAttribute("data-test")',
            'rail-destination-you',
        );
});

test('the sidebar wraps channel navigation in a landmark with an accessible name', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    signInThroughBrowser($alice)
        ->assertAriaAttribute('nav[data-test="channels-nav"]', 'label', 'Channels');
});

test('the active channel row is exposed as the current page to assistive tech', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    // Login lands on #general, so its sidebar row is the current page.
    signInThroughBrowser($alice)
        ->assertPresent(
            '[data-test="section-content-channels"] a[aria-current="page"]',
        );
});

test('the settings sidebar is a labelled landmark with a current-page item', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    signInThroughBrowser($alice)
        // Open the user menu and follow the Settings link (a client-side Inertia
        // visit, so the browser session survives). Gate each step — wait for the
        // menu item to render, then for the settings route to land — before
        // asserting the landmark, so a slow menu/navigation doesn't race the check.
        ->click('@rail-destination-you')
        ->assertPresent('@settings-menu-item')
        // Let the dropdown settle past its open/pointer-grace window, otherwise
        // the item click can be swallowed and never navigate.
        ->wait(0.5)
        ->click('@settings-menu-item')
        ->assertPathContains('/settings')
        ->assertAriaAttribute('nav[data-test="settings-nav"]', 'label', 'Settings')
        ->assertAriaAttribute(
            '[data-test="settings-nav-profile"]',
            'current',
            'page',
        );
});

test('an unread channel exposes its unread state to assistive tech', function (): void {
    ['owner' => $alice, 'member' => $bob, 'team' => $team] = browserTeamWithChannel();

    // A second channel Alice belongs to but has never read, carrying a message
    // from Bob — so her sidebar row for it shows the plain unread indicator.
    $random = Channel::factory()->for($team)->create([
        'name' => 'Random',
        'slug' => 'random',
    ]);
    app(JoinChannel::class)->handle($random, $alice);
    app(JoinChannel::class)->handle($random, $bob);
    Message::factory()->for($random)->for($bob, 'user')->create();

    signInThroughBrowser($alice)
        ->assertPresent('[data-test="channel-unread-random"]');
});

test('a direct message row exposes the participant presence to assistive tech', function (): void {
    ['owner' => $alice, 'member' => $bob, 'team' => $team] = browserTeamWithChannel();

    // Bob is offline (no second client), so the row announces "Offline" via a
    // dedicated screen-reader-only label rather than an aria-label on a bare span.
    app(OpenDirectMessage::class)->handle($team, $alice, $bob);

    signInThroughBrowser($alice)
        ->assertSeeIn('[data-test="dm-presence-label"]', 'Offline');
});

test('the destination rail is a named landmark of keyboard-operable buttons', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    signInThroughBrowser($alice)
        ->resize(1280, 900)
        // The rail is its own landmark, distinct from the conversation list it
        // drives, and each glyph is a real button an accessible name reaches.
        ->assertAttribute('nav[data-test="navigation-rail"]', 'aria-label', 'Destinations')
        ->assertPresent('button[data-test="rail-destination-threads"]')
        ->assertAttribute('[data-test="rail-destination-channels"]', 'aria-current', 'true')
        ->assertScript(<<<'JS'
        (() => ['channels', 'threads', 'reminders', 'search', 'you'].every((destination) => {
            const glyph = document.querySelector(`[data-test="rail-destination-${destination}"]`);

            return glyph !== null && glyph.textContent.trim().length > 0;
        }))()
        JS, true)
        // Activating one from the keyboard swaps the panel, and the press moves
        // with it — only the open destination is marked current.
        ->keys('@rail-destination-reminders', 'Enter')
        ->assertPresent('@destination-panel-reminders')
        ->assertAttribute('[data-test="rail-destination-reminders"]', 'aria-current', 'true')
        ->assertScript(
            '(() => document.querySelector(\'[data-test="rail-destination-channels"]\').getAttribute("aria-current"))()',
            null,
        );
});

test('an open destination panel has no serious accessibility violations, light or dark', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    $page = signInThroughBrowser($alice)
        ->resize(1280, 900)
        ->click('@rail-destination-search')
        ->assertPresent('@destination-panel-search')
        ->wait(0.5)
        ->assertNoAccessibilityIssues();

    switchToDarkTheme($page)
        ->assertNoAccessibilityIssues();
});

test('the mobile tab bar has no serious accessibility violations, light or dark', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    $page = signInThroughBrowser($alice)
        ->resize(390, 844)
        ->click('@sidebar-toggle')
        ->assertPresent('@navigation-tab-bar')
        ->assertAttribute('nav[data-test="navigation-tab-bar"]', 'aria-label', 'Destinations')
        ->assertAttribute('[data-test="tab-destination-channels"]', 'aria-current', 'true')
        // The `--muted-foreground` rows the drawer inherited from the desktop
        // sidebar, pinned visible so the audit below provably reaches them —
        // they are what #946 was opened over.
        ->assertVisible('@quick-switcher-trigger')
        ->assertVisible('@section-toggle-channels')
        ->assertVisible('@section-toggle-direct')
        ->wait(0.5)
        ->assertNoAccessibilityIssues();

    switchToDarkTheme($page)
        ->assertNoAccessibilityIssues();
});
