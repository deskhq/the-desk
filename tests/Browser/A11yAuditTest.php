<?php

declare(strict_types=1);

use App\Models\Message;

test('the authenticated channel page has no serious accessibility violations in either theme', function (): void {
    ['owner' => $alice, 'member' => $bob, 'channel' => $channel] = browserTeamWithChannel();

    // Seed a message so the real message timeline renders during the audit — the
    // list/listitem roles, per-message <time>, and labelled scroll region — not
    // just the empty state. Authored by Bob so Alice sees another member's row.
    $message = Message::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $bob->id,
        'body' => 'Hello from Bob',
    ]);

    // Mark it read so no unread divider or "New messages" jump pill renders: those
    // affordances carry known contrast debt tracked separately, out of scope for
    // this timeline-semantics slice, and would otherwise trip the contrast audit.
    $alice->channels()->updateExistingPivot($channel->id, [
        'last_read_message_id' => $message->id,
    ]);

    // Runs axe-core (bundled with the browser plugin) against the real rendered
    // DOM — the guard rail this a11y effort stands up. The default level is
    // SERIOUS (1), which keeps critical + serious violations; color-contrast
    // failures surface here, so a clean run proves the WCAG AA contrast fixes
    // (#269) hold against the shipped theme tokens.
    $page = signInThroughBrowser($alice)
        ->assertSee('Hello from Bob')
        // Open the mention autocomplete so the combobox/listbox pattern (textarea
        // role=combobox + aria-expanded, listbox, options) is audited live too.
        ->type('@message-composer-input', '@')
        ->assertPresent('#mention-listbox')
        ->assertNoAccessibilityIssues();

    // Re-audit against the dark palette. Persist 'dark' to localStorage — the
    // source of truth `useAppearance` reads — before applying the `.dark` class,
    // otherwise the appearance controller re-resolves 'system' → light and
    // reverts the toggle mid-audit. The settle lets that recompute finish.
    $page->script(<<<'JS'
    () => {
        localStorage.setItem('appearance', 'dark');
        document.documentElement.classList.add('dark');
        document.documentElement.style.colorScheme = 'dark';
    }
    JS);

    $page->wait(0.5)
        ->assertNoAccessibilityIssues();
});
