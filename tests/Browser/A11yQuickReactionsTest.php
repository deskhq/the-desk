<?php

declare(strict_types=1);

use App\Models\Message;
use App\Models\MessageReaction;
use App\Models\User;

/**
 * Seed one of Bob's messages that Alice has already reacted to and read, so the
 * hover bar carries both a pressed and an unpressed shortcut, with no unread
 * divider to trip the contrast audit.
 *
 * @return array{owner: User, message: Message}
 */
function browserTeamWithReactedMessage(): array
{
    ['owner' => $alice, 'member' => $bob, 'channel' => $channel] = browserTeamWithChannel();

    $message = Message::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $bob->id,
        'body' => 'Hello from Bob',
    ]);

    // The default ranking leads with 👍, so reacting with it renders the first
    // shortcut pressed (brass inset ring + fill) during the audit.
    MessageReaction::factory()->for($message)->for($alice)->emoji('👍')->create();

    $alice->channels()->updateExistingPivot($channel->id, [
        'last_read_message_id' => $message->id,
    ]);

    return ['owner' => $alice, 'message' => $message];
}

test('the quick-react cluster names each shortcut and marks the reacted one pressed', function (): void {
    ['owner' => $alice] = browserTeamWithReactedMessage();

    signInThroughBrowser($alice)
        ->assertSee('Hello from Bob')
        ->hover('@message-body')
        ->assertScript(
            "document.querySelectorAll('[data-test=\"quick-react\"]').length",
            5,
        )
        // Every shortcut is a real button carrying its own accessible name, and
        // the picker trigger still closes the cluster.
        ->assertScript(
            "[...document.querySelectorAll('[data-test=\"quick-react\"]')].every(cell => cell.tagName === 'BUTTON' && (cell.getAttribute('aria-label') ?? '') !== '')",
            true,
        )
        // `data-emoji` is shared with the reaction pills, so scope to the bar.
        ->assertAriaAttribute('[data-test="quick-react"][data-emoji="👍"]', 'pressed', 'true')
        ->assertAriaAttribute('[data-test="quick-react"][data-emoji="👍"]', 'label', 'Remove your 👍')
        ->assertAriaAttribute('[data-test="quick-react"][data-emoji="❤️"]', 'pressed', 'false')
        ->assertAriaAttribute('[data-test="quick-react"][data-emoji="❤️"]', 'label', 'React with ❤️');
});

test('the quick cluster and frequently-used strip pass the axe audit in either theme', function (): void {
    ['owner' => $alice] = browserTeamWithReactedMessage();

    $page = signInThroughBrowser($alice)
        ->assertSee('Hello from Bob')
        ->hover('@message-body')
        ->click('@message-react')
        ->assertPresent('[data-test="frequent-emoji-strip"]')
        ->assertNoAccessibilityIssues();

    // Re-audit against the dark palette, where the pressed shortcut swaps to the
    // brass-tint fill. Persist the choice before applying the class so the
    // appearance controller doesn't re-resolve 'system' back to light.
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
