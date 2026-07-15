<?php

declare(strict_types=1);

use App\Actions\Teams\CreateTeam;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;

/**
 * Seed one of Bob's messages, already read by Alice, so her timeline carries a
 * real row to react to without an unread divider tripping the contrast audit.
 *
 * @return array{owner: User, channel: Channel}
 */
function browserTeamWithReadMessage(): array
{
    ['owner' => $alice, 'member' => $bob, 'channel' => $channel] = browserTeamWithChannel();

    $message = Message::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $bob->id,
        'body' => 'Hello from Bob',
    ]);

    $alice->channels()->updateExistingPivot($channel->id, [
        'last_read_message_id' => $message->id,
    ]);

    return ['owner' => $alice, 'channel' => $channel];
}

test('the emoji picker exposes a labelled, keyboard-navigable listbox', function (): void {
    ['owner' => $alice] = browserTeamWithReadMessage();

    signInThroughBrowser($alice)
        ->assertSee('Hello from Bob')
        // Reveal the hover action bar on Bob's row, then open the reaction picker.
        ->hover('@message-body')
        ->click('@message-react')
        ->assertPresent('.v3-emoji-picker .v3-emojis button')
        // The search field, placeholder-only in the library, gains a name.
        ->assertAriaAttribute('.v3-search input', 'label', 'Search emoji')
        // The grid body names the whole picker; each category is its own listbox.
        ->assertAttribute('.v3-body-inner', 'role', 'group')
        ->assertAriaAttribute('.v3-body-inner', 'label', 'Emoji')
        ->assertScript(
            "[...document.querySelectorAll('.v3-emojis')].every(list => list.getAttribute('role') === 'listbox' && (list.getAttribute('aria-label') ?? '') !== '')",
            true,
        )
        // Every cell is an option with an accessible name (its glyph).
        ->assertScript(
            "[...document.querySelectorAll('.v3-emojis button')].every(cell => cell.getAttribute('role') === 'option')",
            true,
        )
        // A single roving tab stop: the first cell is tabbable, the rest are not.
        ->assertScript(
            "document.querySelector('.v3-emojis button').tabIndex === 0",
            true,
        )
        ->assertScript(
            "[...document.querySelectorAll('.v3-emojis button')].slice(1).every(cell => cell.tabIndex === -1)",
            true,
        )
        // Arrow keys drive the grid: Right steps to the next cell.
        ->assertScript(<<<'JS'
        (() => {
            const cells = [...document.querySelectorAll('.v3-emojis button')];
            cells[0].focus();
            cells[0].dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true, cancelable: true }));
            return document.activeElement === cells[1];
        })()
        JS, true)
        // Down drops a row, proving the geometric row math against real layout.
        ->assertScript(<<<'JS'
        (() => {
            const before = document.activeElement;
            before.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true, cancelable: true }));
            const after = document.activeElement;
            return after !== before
                && after.matches('.v3-emojis button')
                && after.getBoundingClientRect().top > before.getBoundingClientRect().top;
        })()
        JS, true)
        ->assertNoAccessibilityIssues();
});

test('the emoji picker follows the dark appearance', function (): void {
    ['owner' => $alice] = browserTeamWithReadMessage();

    // Emulate a dark-preferring OS so `useAppearance` resolves to dark and the
    // picker's reactive `:theme` renders dark — the surface the user reported
    // came out white before.
    visit('/login')
        ->inDarkMode()
        ->type('#email', $alice->email)
        ->type('#password', 'password')
        ->click('@login-button')
        ->assertPathIsNot('/login')
        ->assertSee('Hello from Bob')
        ->hover('@message-body')
        ->click('@message-react')
        ->assertPresent('.v3-emoji-picker .v3-emojis button')
        // The picker surface is a dark fill (low RGB sum), not the library's
        // default white.
        ->assertScript(<<<'JS'
        (() => {
            const bg = getComputedStyle(document.querySelector('.v3-emoji-picker')).backgroundColor;
            const [r, g, b] = bg.match(/\d+/g).map(Number);
            return r + g + b < 300;
        })()
        JS, true)
        ->assertNoAccessibilityIssues();
});

test('the onboarding tour is an accessible, focus-trapped dialog', function (): void {
    // A brand-new owner whose first-run tour auto-starts on the channel page.
    $owner = User::factory()->notOnboarded()->create(['name' => 'Casey New']);
    app(CreateTeam::class)->handle($owner, 'Acme');

    $page = signInThroughBrowser($owner)
        ->assertPresent('@onboarding-tour')
        // The dialog names itself from its heading, so it is not an anonymous
        // "dialog" to a screen reader.
        ->assertAttribute('@onboarding-tour', 'aria-labelledby', 'onboarding-tour-title')
        ->assertPresent('#onboarding-tour-title')
        // Focus is moved into the overlay on open (FocusScope), so Escape works
        // regardless of where focus sat before and Tab cannot escape the tour.
        ->assertScript(
            "document.querySelector('[data-test=onboarding-tour]').contains(document.activeElement)",
            true,
        )
        ->assertNoAccessibilityIssues();

    // Escape dismisses the tour from wherever focus landed inside it.
    $page->keys('@onboarding-next', ['Escape'])
        ->assertMissing('@onboarding-tour');
});
