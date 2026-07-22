<?php

declare(strict_types=1);

use App\Models\Message;

test('a user sets a status from the user menu and it lands on their name', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    $page = signInThroughBrowser($alice)
        ->click('@sidebar-menu-button')
        // With nothing set, the presence section offers the plain entry point.
        ->assertPresent('@set-status-menu-item')
        ->click('@set-status-menu-item')
        ->assertPresent('@status-dialog')
        ->assertSee('Set a status')
        // A quick pick fills emoji, text, and its default expiry in one tap.
        ->click('[data-preset="meeting"]')
        ->assertValue('@status-text-input', 'In a meeting')
        ->click('@status-save');

    // Saving closes the dialog and the status is persisted.
    $page->wait(0.5)->assertNotPresent('@status-dialog');

    expect($alice->refresh()->status_text)->toBe('In a meeting')
        ->and($alice->status_emoji)->toBe('📅')
        ->and($alice->status_expires_at)->not->toBeNull();

    // And the emoji now rides beside the name, on the menu masthead and the
    // status card that has replaced the "Set a status" row.
    $page->click('@sidebar-menu-button')
        ->assertPresent('@edit-status-menu-item')
        ->assertNotPresent('@set-status-menu-item')
        ->assertPresent('@user-status-emoji');
});

test('a free-form status with no chosen emoji saves under a default one', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    signInThroughBrowser($alice)
        ->click('@sidebar-menu-button')
        ->click('@set-status-menu-item')
        ->type('@status-text-input', 'Heads down')
        ->click('@status-save')
        ->wait(0.5)
        ->assertNotPresent('@status-dialog');

    expect($alice->refresh()->status_text)->toBe('Heads down')
        ->and($alice->status_emoji)->toBe('💬');
});

test('the menu row clears a status in one tap without opening the dialog', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    $alice->forceFill([
        'status_emoji' => '📅',
        'status_text' => 'In a meeting',
        'status_expires_at' => now()->addHour(),
    ])->save();

    signInThroughBrowser($alice)
        ->click('@sidebar-menu-button')
        ->assertPresent('@clear-status-menu-item')
        ->click('@clear-status-menu-item')
        ->wait(0.8)
        // The dialog never opens, and the row flips back in place — the menu
        // stays open, the way the theme and sidebar switchers above it do.
        ->assertNotPresent('@status-dialog')
        ->assertPresent('@set-status-menu-item');

    expect($alice->refresh()->status_emoji)->toBeNull()
        ->and($alice->status_text)->toBeNull()
        ->and($alice->status_expires_at)->toBeNull();
});

test('a teammate status shows beside their name and in full on their hover card', function (): void {
    ['owner' => $alice, 'member' => $bob, 'channel' => $channel] = browserTeamWithChannel();

    $bob->forceFill(['status_emoji' => '🤒', 'status_text' => 'Out sick'])->save();

    Message::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $bob->id,
        'body' => 'Hello from Bob',
    ]);

    signInThroughBrowser($alice)
        ->assertSee('Hello from Bob')
        // Inline beside the author's name, announced as the status rather than
        // as a bare glyph.
        ->assertAttribute('[data-test="user-status-emoji"]', 'aria-label', 'Bob Member is Out sick')
        // And in full — emoji plus text — on the hover card the name opens.
        ->hover('@message-author-name')
        ->wait(1.5)
        ->assertPresent('@hover-card-status')
        ->assertSee('Out sick');
});

test('a lapsed status renders nothing at all', function (): void {
    ['owner' => $alice, 'member' => $bob, 'channel' => $channel] = browserTeamWithChannel();

    // Set but already expired: the lazy read masks it everywhere, with no
    // reserved slot left behind.
    $bob->forceFill([
        'status_emoji' => '🤒',
        'status_text' => 'Out sick',
        'status_expires_at' => now()->subMinute(),
    ])->save();

    Message::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $bob->id,
        'body' => 'Hello from Bob',
    ]);

    signInThroughBrowser($alice)
        ->assertSee('Hello from Bob')
        ->assertNotPresent('@user-status-emoji')
        ->assertDontSee('Out sick');
});

test('the status dialog passes the axe audit in either theme', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    $page = signInThroughBrowser($alice)
        ->click('@sidebar-menu-button')
        ->click('@set-status-menu-item')
        ->assertPresent('@status-dialog')
        // Let the dialog's entrance transition settle before axe reads the DOM,
        // so a mid-animation opacity never manufactures a contrast failure.
        ->wait(0.5)
        ->assertNoAccessibilityIssues();

    // Re-audit against the dark palette, seeding localStorage first so the
    // appearance controller doesn't re-resolve 'system' → light mid-audit.
    $page->script(<<<'JS'
    () => {
        localStorage.setItem('appearance', 'dark');
        document.documentElement.classList.add('dark');
        document.documentElement.style.colorScheme = 'dark';
    }
    JS);

    $page->wait(0.5)->assertNoAccessibilityIssues();
});

test('the status card exposes menu semantics and a named clear control', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    $alice->forceFill([
        'status_emoji' => '📅',
        'status_text' => 'In a meeting',
        'status_expires_at' => now()->addHour(),
    ])->save();

    // A whole-page axe audit cannot run with a dropdown menu open: reka marks
    // the rest of the shell aria-hidden, which traps the focusable skip link and
    // trips `aria-hidden-focus` on untouched code (#730). Until that is fixed,
    // pin the card's own semantics directly instead.
    signInThroughBrowser($alice)
        ->click('@sidebar-menu-button')
        // Both rows are real menu items, so arrow-key navigation reaches them and
        // Enter/Space activates them.
        ->assertAttribute('[data-test="edit-status-menu-item"]', 'role', 'menuitem')
        ->assertAttribute('[data-test="clear-status-menu-item"]', 'role', 'menuitem')
        // The ✕ is icon-only, so it carries its own name.
        ->assertAttribute('[data-test="clear-status-menu-item"]', 'aria-label', 'Clear status')
        // `role="menu"` admits only menu-role children, so the emoji inside the
        // card is decorative — the card's text says what the status is.
        ->assertScript(
            "document.querySelector('[data-test=\"user-status-emoji\"]').getAttribute('aria-hidden')",
            'true',
        );
});
