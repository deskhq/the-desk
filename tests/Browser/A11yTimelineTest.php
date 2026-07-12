<?php

declare(strict_types=1);

use App\Models\Channel;
use App\Models\Message;
use App\Models\User;

/**
 * Seed two of Bob's messages — the second replying to the first — and park
 * Alice's read pointer at the first, so the timeline she opens carries a reply
 * quote and a "New messages" divider above the unread reply.
 *
 * @return array{owner: User, channel: Channel}
 */
function timelineWithReplyAndUnread(): array
{
    ['owner' => $alice, 'member' => $bob, 'channel' => $channel] = browserTeamWithChannel();

    $first = Message::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $bob->id,
        'body' => 'First message',
        'created_at' => now()->subMinutes(2),
    ]);

    Message::factory()->replyTo($first)->create([
        'channel_id' => $channel->id,
        'user_id' => $bob->id,
        'body' => 'A reply to the first',
        'created_at' => now()->subMinute(),
    ]);

    // Alice has read up to the first message, so the reply is her first unread —
    // the divider freezes above it when she opens the channel.
    $alice->channels()->updateExistingPivot($channel->id, [
        'last_read_message_id' => $first->id,
    ]);

    return ['owner' => $alice, 'channel' => $channel];
}

test('each message row is a labelled listitem with a semantic time', function (): void {
    ['owner' => $alice] = timelineWithReplyAndUnread();

    signInThroughBrowser($alice)
        ->assertSee('First message')
        // Every message is a list item whose accessible name composes the author
        // and the time, and carries a machine-readable <time datetime>.
        ->assertPresent('[role="list"] [role="listitem"]')
        ->assertScript(
            "[...document.querySelectorAll('[role=listitem]')].every(el => (el.getAttribute('aria-label') ?? '').includes('Bob Member'))",
            true,
        )
        ->assertPresent('[role="listitem"] time[datetime]');
});

test('the message history is a focusable, labelled region', function (): void {
    ['owner' => $alice] = timelineWithReplyAndUnread();

    signInThroughBrowser($alice)
        ->assertSee('First message')
        ->assertAttribute('[role="region"][aria-label="Message history"]', 'tabindex', '0');
});

test('the reply quote button names the message it jumps to', function (): void {
    ['owner' => $alice] = timelineWithReplyAndUnread();

    signInThroughBrowser($alice)
        ->assertSee('A reply to the first')
        ->assertAriaAttribute(
            '[data-test="message-quote"]',
            'label',
            'Jump to replied message from Bob Member',
        );
});

test('the unread divider is a labelled separator', function (): void {
    ['owner' => $alice] = timelineWithReplyAndUnread();

    signInThroughBrowser($alice)
        ->assertSee('A reply to the first')
        ->assertAttribute('[data-test="unread-divider"]', 'role', 'separator')
        ->assertAriaAttribute('[data-test="unread-divider"]', 'label', 'New messages');
});

test('the composer exposes the mention autocomplete as a combobox', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    signInThroughBrowser($alice)
        ->assertPresent('@message-composer-input')
        // The textarea is a combobox whose accessible name mirrors its
        // placeholder, and it advertises list autocompletion, collapsed at rest.
        ->assertAttribute('[data-test="message-composer-input"]', 'role', 'combobox')
        ->assertAttribute('[data-test="message-composer-input"]', 'aria-autocomplete', 'list')
        ->assertAriaAttribute('[data-test="message-composer-input"]', 'expanded', 'false')
        ->assertScript(
            "(el => el.getAttribute('aria-label') === el.getAttribute('placeholder'))(document.querySelector('[data-test=message-composer-input]'))",
            true,
        )
        // Typing '@' opens the listbox and wires the active-descendant contract.
        ->type('@message-composer-input', '@')
        ->assertPresent('#mention-listbox[role="listbox"]')
        ->assertAttribute('[data-test="mention-option"]', 'role', 'option')
        ->assertAriaAttribute('[data-test="message-composer-input"]', 'expanded', 'true')
        ->assertAttribute('[data-test="message-composer-input"]', 'aria-controls', 'mention-listbox')
        ->assertAttribute(
            '[data-test="message-composer-input"]',
            'aria-activedescendant',
            'mention-option-0',
        )
        ->assertAriaAttribute('[data-test="mention-option"]', 'selected', 'true');
});

test('a labelled polite live region stands ready for send failures', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    signInThroughBrowser($alice)
        ->assertAttribute('[data-test="send-failure-announcer"]', 'aria-live', 'polite');
});
