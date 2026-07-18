<?php

declare(strict_types=1);

use App\Models\Message;
use App\Models\User;

/**
 * Both the main channel timeline and the thread panel virtualize from row-height
 * estimates and only measure a row's real height once it renders. On initial open
 * of a long list of tall rows, the auto-pin to the newest message used to release
 * its bottom-glue on a fixed frame budget — which could expire while rows near the
 * bottom were still measuring, so the true bottom moved down and the view was left
 * a screen or more short of the newest message, with the jump-to-latest pill shown
 * (#500). These tests assert the newest row is *actually* within the visible
 * viewport on open (a bounding-rect check — the reported scroll gap is unreliable
 * mid-measurement) and that no jump pill is offered.
 */

/**
 * Seed a channel with enough tall, alternating-author messages that the timeline
 * virtualizes and stands far taller than the viewport, so the initial pin has many
 * unmeasured rows to settle over.
 *
 * @return array{owner: User, latest: int}
 */
function crowdedInitialTimeline(): array
{
    ['owner' => $alice, 'member' => $bob, 'channel' => $channel] = browserTeamWithChannel();

    $authors = [$alice, $bob];
    $body = str_repeat("lorem ipsum dolor sit amet consectetur\n", 4);
    $latest = 60;

    // Alternating authors keep every message its own avatar group (more rows), and
    // the multi-line bodies make a real row dwarf its 84px estimate — the height
    // gap that used to strand the initial auto-pin short of the newest message.
    foreach (range(1, $latest) as $i) {
        Message::factory()->create([
            'channel_id' => $channel->id,
            'user_id' => $authors[$i % 2]->id,
            'body' => "Message {$i}\n{$body}",
            'created_at' => now()->subMinutes(120 - $i),
        ]);
    }

    return ['owner' => $alice, 'latest' => $latest];
}

/**
 * Seed a channel whose newest message roots a long thread of tall, alternating
 * -author replies, so the panel's reply list virtualizes and stands well taller
 * than the panel viewport.
 *
 * @return array{owner: User, replyCount: int}
 */
function crowdedInitialThread(): array
{
    ['owner' => $alice, 'member' => $bob, 'channel' => $channel] = browserTeamWithChannel();

    $root = Message::factory()->for($channel)->for($alice)->create([
        'body' => 'Thread root message',
        'created_at' => now()->subMinutes(90),
    ]);

    $authors = [$alice, $bob];
    $body = str_repeat("lorem ipsum dolor sit amet consectetur\n", 4);
    $replyCount = 80;

    foreach (range(1, $replyCount) as $i) {
        Message::factory()->for($channel)->inThread($root)->create([
            'user_id' => $authors[$i % 2]->id,
            'body' => "Reply {$i}\n{$body}",
            'created_at' => now()->subMinutes(60)->addSeconds($i),
        ]);
    }

    $root->forceFill([
        'reply_count' => $replyCount,
        'last_reply_at' => now(),
    ])->save();

    return ['owner' => $alice, 'replyCount' => $replyCount];
}

test('the channel timeline lands on the newest message on initial open', function (): void {
    ['owner' => $alice, 'latest' => $latest] = crowdedInitialTimeline();

    $page = signInThroughBrowser($alice)->assertSee("Message {$latest}");

    // Let the initial auto-pin and the virtualizer's measurement settle.
    $page->wait(2);

    // The newest message row is actually within the scroll container's viewport —
    // a bounding-rect check, since the reported scrollHeight under-reports the true
    // bottom while rows are still measuring. A short landing would leave the newest
    // row below the fold (not intersecting the container's rect).
    $page->assertScript(
        <<<'JS'
        (() => {
            const el = document.querySelector('[data-test=message-history]');
            const rows = [...el.querySelectorAll('[id^="message-"]')];
            const last = rows[rows.length - 1];
            if (!last) return false;
            const c = el.getBoundingClientRect();
            const r = last.getBoundingClientRect();
            const intersectsViewport = r.top < c.bottom && r.bottom > c.top;
            // Pinned: the newest row sits at the foot of the viewport, not a screen
            // or more above it.
            const nearBottom = Math.abs(c.bottom - r.bottom) <= 120;
            return intersectsViewport && nearBottom;
        })()
        JS,
        true,
    )
        // No jump-to-latest pill: the view is pinned to the present, not stranded.
        ->assertNotPresent('[data-test=jump-to-latest]');
});

test('the thread panel lands on the newest reply on initial open', function (): void {
    ['owner' => $alice, 'replyCount' => $replyCount] = crowdedInitialThread();

    $page = signInThroughBrowser($alice)->assertSee('Thread root message');

    // Open the thread from the main-timeline summary and let the reply page land.
    $page->click('[data-test=thread-summary]')
        ->assertPresent('[data-test=thread-panel]')
        ->assertSee("Reply {$replyCount}")
        ->wait(2);

    // The newest reply row is within the panel's scroll viewport on open.
    $page->assertScript(
        <<<'JS'
        (() => {
            const panel = document.querySelector('[data-test=thread-panel]');
            const el = panel.querySelector('[data-index]').closest('.overflow-y-auto');
            const rows = [...el.querySelectorAll('[id^="message-"]')];
            const last = rows[rows.length - 1];
            if (!last) return false;
            const c = el.getBoundingClientRect();
            const r = last.getBoundingClientRect();
            const intersectsViewport = r.top < c.bottom && r.bottom > c.top;
            const nearBottom = Math.abs(c.bottom - r.bottom) <= 120;
            return intersectsViewport && nearBottom;
        })()
        JS,
        true,
    )
        ->assertNotPresent('[data-test=jump-to-latest-thread]');
});
