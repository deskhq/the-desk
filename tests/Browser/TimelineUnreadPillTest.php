<?php

declare(strict_types=1);

use App\Models\Message;
use App\Models\User;

/**
 * Seed a channel with an unread boundary partway up a virtualized timeline: 30
 * tall, alternating-author messages, with the owner's read pointer frozen at an
 * early message so a long tail of peer messages is unread. The alternating
 * authors keep every message its own avatar group (so a peer message always sits
 * in the unread tail to anchor the "New messages" divider), and the long bodies
 * make the list stand taller than the viewport so it virtualizes and the divider
 * scrolls off-screen above the window.
 *
 * @return array{owner: User}
 */
function unreadCrowdedTimeline(): array
{
    ['owner' => $alice, 'member' => $bob, 'channel' => $channel] = browserTeamWithChannel();

    $authors = [$alice, $bob];
    $body = str_repeat("lorem ipsum dolor sit amet\n", 3);

    /** @var array<int, string> $messageIds */
    $messageIds = [];

    foreach (range(1, 30) as $i) {
        $message = Message::factory()->create([
            'channel_id' => $channel->id,
            'user_id' => $authors[$i % 2]->id,
            'body' => "Message {$i}\n{$body}",
            'created_at' => now()->subMinutes(60 - $i),
        ]);

        $messageIds[$i] = $message->id;
    }

    // Freeze Alice's read pointer near the top so messages 6..30 are unread and
    // the "New messages" divider sits well above the bottom-pinned window at open.
    $channel->members()->updateExistingPivot($alice->id, [
        'last_read_message_id' => $messageIds[5],
    ]);

    return ['owner' => $alice];
}

test('the New messages pill does not reappear after jumping to present', function (): void {
    ['owner' => $alice] = unreadCrowdedTimeline();

    $page = signInThroughBrowser($alice)->assertSee('Message 30');

    // Let the initial open settle (it lands on the newest message, leaving the
    // frozen unread divider above the window) before driving the pills.
    $page->wait(1)
        // On open the rose "New messages" pill shows: the unread boundary is above
        // the bottom-pinned window.
        ->assertPresent('[data-test=jump-to-unread]')
        // Click it — the timeline scrolls the divider into view, so the pill hides
        // and the boundary is latched as seen for this visit.
        ->click('[data-test=jump-to-unread]')
        ->wait(1)
        ->assertNotPresent('[data-test=jump-to-unread]')
        // Return to the present. The frozen divider now sits above the window
        // again, but the reader has already seen it, so the pill must stay
        // dismissed rather than reappearing (#411).
        ->assertPresent('[data-test=jump-to-latest]')
        ->click('[data-test=jump-to-latest]')
        ->wait(2)
        ->assertNotPresent('[data-test=jump-to-unread]');
});
