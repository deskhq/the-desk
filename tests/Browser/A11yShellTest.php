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
        ->click('@sidebar-menu-button')
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
