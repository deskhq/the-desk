<?php

declare(strict_types=1);

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
