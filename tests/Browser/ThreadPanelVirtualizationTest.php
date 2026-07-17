<?php

declare(strict_types=1);

use App\Models\Message;
use App\Models\User;

/**
 * Seed a channel whose newest message roots a long thread: enough tall,
 * alternating-author replies that the thread panel's reply list stands far
 * taller than the panel viewport and virtualizes, so only a window of reply rows
 * is ever mounted. Every reply fits in the first page (THREAD_PAGE_SIZE = 50), so
 * the windowing shows on the initial open without paging older replies in.
 *
 * @return array{owner: User, root: Message, replyCount: int}
 */
function crowdedThread(): array
{
    ['owner' => $alice, 'member' => $bob, 'channel' => $channel] = browserTeamWithChannel();

    $root = Message::factory()->for($channel)->for($alice)->create([
        'body' => 'Thread root message',
        'created_at' => now()->subMinutes(90),
    ]);

    $authors = [$alice, $bob];
    $body = str_repeat("lorem ipsum dolor sit amet\n", 3);
    // More than one reply page (THREAD_PAGE_SIZE = 50) so the loaded window stands
    // well taller than the viewport and the initial pin has many unmeasured rows
    // to settle over — the regime where a windowed jump can land short (#347).
    $replyCount = 80;

    // Alternating authors keep every reply its own avatar group (more rows), and
    // the multi-line bodies make each row dwarf the fixed scrub skeleton.
    foreach (range(1, $replyCount) as $i) {
        Message::factory()->for($channel)->inThread($root)->create([
            'user_id' => $authors[$i % 2]->id,
            'body' => "Reply {$i}\n{$body}",
            'created_at' => now()->subMinutes(60)->addSeconds($i),
        ]);
    }

    // The header count and the main-timeline thread summary read the root's
    // denormalized aggregates, which the seeding above doesn't bump.
    $root->forceFill([
        'reply_count' => $replyCount,
        'last_reply_at' => now(),
    ])->save();

    return ['owner' => $alice, 'root' => $root, 'replyCount' => $replyCount];
}

test('a long thread renders only a window of reply rows', function (): void {
    ['owner' => $alice, 'replyCount' => $replyCount] = crowdedThread();

    $page = signInThroughBrowser($alice)->assertSee('Thread root message');

    // Open the thread from the main-timeline summary and let the reply page land.
    $page->click('[data-test=thread-summary]')
        ->assertPresent('[data-test=thread-panel]')
        ->assertSee("Reply {$replyCount}")
        ->wait(1);

    // Windowed: far fewer rows are mounted than the thread has replies. Without
    // virtualization every reply (plus the root) would be in the DOM, so this
    // strict inequality only holds once the reply list windows.
    $page->assertScript(
        <<<JS
        (() => {
            const panel = document.querySelector('[data-test=thread-panel]');
            return panel.querySelectorAll('[data-index]').length < {$replyCount};
        })()
        JS,
        true,
    );
});

test('the windowed thread panel jumps back to the newest reply after scrolling up', function (): void {
    ['owner' => $alice, 'replyCount' => $replyCount] = crowdedThread();

    $page = signInThroughBrowser($alice)->assertSee('Thread root message');

    $page->click('[data-test=thread-summary]')
        ->assertPresent('[data-test=thread-panel]')
        ->assertSee("Reply {$replyCount}")
        ->wait(1);

    // Scroll the reply list to the top so the panel unpins and its jump pill shows.
    $page->script(<<<'JS'
    () => {
        const panel = document.querySelector('[data-test=thread-panel]');
        const el = panel.querySelector('[data-index]').closest('.overflow-y-auto');
        el.scrollTop = 0;
        el.dispatchEvent(new Event('scroll'));
    }
    JS);

    // The thread's jump-to-latest pill appears once scrolled up; a single click
    // must drive the windowed list all the way back to the newest reply (#347).
    $page->wait(1)
        ->assertPresent('[data-test=jump-to-latest-thread]')
        ->click('[data-test=jump-to-latest-thread]')
        ->wait(2)
        ->assertScript(
            <<<'JS'
            (() => {
                const panel = document.querySelector('[data-test=thread-panel]');
                const el = panel.querySelector('[data-index]').closest('.overflow-y-auto');
                return el.scrollHeight - el.scrollTop - el.clientHeight <= 120;
            })()
            JS,
            true,
        )
        // The pill hides once the bottom is reached (pinned state restored).
        ->assertNotPresent('[data-test=jump-to-latest-thread]');
});
