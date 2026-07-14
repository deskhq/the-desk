<?php

declare(strict_types=1);

use App\Models\Message;
use App\Models\User;

/**
 * Seed a channel with enough tall, alternating-author messages that the timeline
 * virtualizes and stands far taller than the viewport. Alternating authors keeps
 * every message its own avatar group (more rows), and the long bodies make a real
 * row dwarf the fixed 56px scrub skeleton — the height gap that used to strand
 * the jump-to-present scroll short of the newest message (#347).
 *
 * @return array{owner: User, latest: string}
 */
function crowdedTimeline(): array
{
    ['owner' => $alice, 'member' => $bob, 'channel' => $channel] = browserTeamWithChannel();

    $authors = [$alice, $bob];
    $latest = '';

    // Enough alternating-author rows (each its own avatar group) with multi-line
    // bodies that the timeline virtualizes and stands taller than the viewport,
    // so scrolling to the top unpins it and reveals the jump-to-present pill.
    $body = str_repeat("lorem ipsum dolor sit amet\n", 3);

    foreach (range(1, 30) as $i) {
        $latest = "Message {$i}\n{$body}";

        Message::factory()->create([
            'channel_id' => $channel->id,
            'user_id' => $authors[$i % 2]->id,
            'body' => $latest,
            'created_at' => now()->subMinutes(60 - $i),
        ]);
    }

    return ['owner' => $alice, 'latest' => $latest];
}

test('a single jump-to-present click reaches the newest message despite scrub skeletons', function (): void {
    ['owner' => $alice] = crowdedTimeline();

    $page = signInThroughBrowser($alice)->assertSee('Message 30');

    // Let the initial open (which anchors on the unread boundary) settle before
    // driving the scroll, so its late positioning can't race the manual scroll.
    $page->wait(1);

    // Scroll to the top of the loaded history so the timeline unpins and the
    // floating jump-to-latest pill appears.
    $page->script(<<<'JS'
    () => {
        const region = document.querySelector('[data-test=message-history]');
        region.scrollTop = 0;
        region.dispatchEvent(new Event('scroll'));
    }
    JS);

    $page->wait(1)
        ->assertPresent('[data-test=jump-to-latest]')
        // A single click must land at the present.
        ->click('[data-test=jump-to-latest]')
        // Allow the smooth scroll, the skeleton→content settle, and the
        // virtualizer's reconcile to converge on the true bottom.
        ->wait(2)
        // Pinned: the container now rests within the near-bottom threshold (120px)
        // of the real bottom instead of stranded above it.
        ->assertScript(
            <<<'JS'
            (() => {
                const el = document.querySelector('[data-test=message-history]');
                return el.scrollHeight - el.scrollTop - el.clientHeight <= 120;
            })()
            JS,
            true,
        )
        // The pill hides once the bottom is reached (pinned state restored).
        ->assertNotPresent('[data-test=jump-to-latest]');
});
