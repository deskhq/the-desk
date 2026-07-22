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

test('an open dropdown menu has no serious accessibility violations', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    // Opening a reka dropdown marks the whole shell `aria-hidden` so assistive
    // tech stays inside the menu. Everything it hides must leave the tab order
    // with it, or the skip link, sidebar and composer stay keyboard-reachable
    // from inside an ARIA-hidden region (`aria-hidden-focus`, #730).
    signInThroughBrowser($alice)
        ->click('@sidebar-menu-button')
        ->assertPresent('@settings-menu-item')
        // Let the menu's entrance transition settle before axe reads the DOM.
        ->wait(0.5)
        ->assertNoAccessibilityIssues();
});

test('the unread jump-to-unread pill has no serious accessibility violations in either theme', function (): void {
    ['owner' => $alice, 'member' => $bob, 'channel' => $channel] = browserTeamWithChannel();

    // Seed one read message to anchor Alice's read pointer, then a long run of
    // unread ones. On mount the timeline auto-scrolls to the newest message, so
    // the unread boundary lands far above the rendered window — the state that
    // surfaces the floating "New messages" jump pill (`showJumpToUnread`). Its
    // white-on-rose fill carried known contrast debt (#278) and, unlike the
    // divider token, isn't a theme variable the CSS-parse contrast spec can
    // reach — so a rendered axe pass is the only guard for the darkened pill.
    $read = Message::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $bob->id,
        'body' => 'Anchor message',
        'created_at' => now()->subMinutes(41),
    ]);

    foreach (range(1, 40) as $i) {
        Message::factory()->create([
            'channel_id' => $channel->id,
            'user_id' => $bob->id,
            'body' => "Unread message {$i}",
            'created_at' => now()->subMinutes(41 - $i),
        ]);
    }

    $alice->channels()->updateExistingPivot($channel->id, [
        'last_read_message_id' => $read->id,
    ]);

    $page = signInThroughBrowser($alice)
        ->assertSee('Anchor message');

    // Let the initial open settle first: it anchors on the unread divider (pill
    // hidden, boundary on-screen), and that scroll animation would otherwise
    // override the programmatic scroll below and leave the divider in view.
    $page->wait(1);

    // The channel opens anchored on the unread divider, which keeps the pill
    // hidden (the boundary is on-screen). Scroll the message-history region to the
    // bottom so the virtualizer drops the divider off the top of its window — the
    // exact condition (`dividerIndex < startIndex`) that reveals the jump pill.
    $page->script(<<<'JS'
    () => {
        const region = document.querySelector('[role="region"]');
        region.scrollTop = region.scrollHeight;
        region.dispatchEvent(new Event('scroll'));
    }
    JS);

    $page->wait(1)
        ->assertPresent('[data-test="jump-to-unread"]')
        ->assertNoAccessibilityIssues();

    // Re-audit the pill against the dark palette (see the sibling test for why the
    // localStorage write must precede the `.dark` class).
    $page->script(<<<'JS'
    () => {
        localStorage.setItem('appearance', 'dark');
        document.documentElement.classList.add('dark');
        document.documentElement.style.colorScheme = 'dark';
    }
    JS);

    $page->wait(0.5)
        ->assertPresent('[data-test="jump-to-unread"]')
        ->assertNoAccessibilityIssues();
});
