<?php

declare(strict_types=1);

use App\Enums\AppLocale;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;

/**
 * The thread panel below `md`: a full-screen push over the channel with a back
 * chevron (per the mobile design's `m3`), never a side pane — a side pane on a
 * 390px viewport leaves neither the channel nor the thread readable. At and
 * above `md` the panel stays the side pane it is on desktop.
 */

/**
 * Seed the shared #general with a rooted thread: an old root with two replies,
 * then enough tall filler messages after it that the channel timeline stands
 * taller than a phone viewport and scrolls.
 *
 * @return array{owner: User, member: User, channel: Channel, root: Message}
 */
function threadedChannel(): array
{
    ['owner' => $alice, 'member' => $bob, 'channel' => $channel] = browserTeamWithChannel();

    $root = Message::factory()->for($channel)->for($alice)->create([
        'body' => 'Thread root message',
        'created_at' => now()->subMinutes(90),
    ]);

    foreach ([1, 2] as $i) {
        Message::factory()->for($channel)->inThread($root)->create([
            'user_id' => $bob->id,
            'body' => "Thread reply {$i}",
            'created_at' => now()->subMinutes(80)->addSeconds($i),
        ]);
    }

    // The timeline summary and the panel's counts read the root's denormalized
    // aggregates, which the seeding above doesn't bump.
    $root->forceFill([
        'reply_count' => 2,
        'last_reply_at' => now()->subMinutes(80),
    ])->save();

    $filler = str_repeat("lorem ipsum dolor sit amet\n", 2);

    foreach (range(1, 15) as $i) {
        Message::factory()->for($channel)->for($alice)->create([
            'body' => "Filler {$i}\n{$filler}",
            'created_at' => now()->subMinutes(60)->addSeconds($i),
        ]);
    }

    return ['owner' => $alice, 'member' => $bob, 'channel' => $channel, 'root' => $root];
}

test('below md a thread opens as a full-screen push over the whole card', function (int $width, int $height): void {
    ['owner' => $alice] = threadedChannel();

    $page = signInThroughBrowser($alice)
        ->resize($width, $height)
        ->assertSee('Thread root message');

    $page->click('[data-test=thread-summary]')
        ->assertPresent('[data-test=thread-panel]')
        ->assertVisible('[data-test=thread-back]')
        // The push covers the card edge to edge — the masthead included, so no
        // squashed two-column layout survives at any phone width.
        ->assertScript(<<<'JS'
        (() => {
            const card = document.querySelector('#main').getBoundingClientRect();
            const panel = document.querySelector('[data-test=thread-panel]').getBoundingClientRect();

            return Math.abs(panel.width - card.width) <= 4
                && Math.abs(panel.top - card.top) <= 4
                && Math.abs(panel.bottom - card.bottom) <= 4;
        })()
        JS, true)
        // The channel pane keeps its full width beneath the push rather than
        // being crushed beside it.
        ->assertScript(<<<'JS'
        (() => {
            const card = document.querySelector('#main').getBoundingClientRect();
            const composer = document.querySelector('[data-tour="composer"]').getBoundingClientRect();

            return composer.width > card.width / 2;
        })()
        JS, true)
        // The desktop close affordance gives way to the back chevron.
        ->assertScript(<<<'JS'
        (() => {
            const close = document.querySelector('[data-test=thread-close]');

            return close === null || close.offsetParent === null;
        })()
        JS, true)
        // The reply count moves out of the header into the rule that separates
        // the root from its replies.
        ->assertVisible('[data-test=thread-replies-divider]')
        ->assertSee('2 replies');
})->with([
    'iPhone 14' => [390, 844],
    'landscape phone' => [740, 360],
    'the last mobile width' => [767, 800],
]);

test('at and above md the thread stays the side pane it is today', function (int $width): void {
    ['owner' => $alice] = threadedChannel();

    $page = signInThroughBrowser($alice)
        ->resize($width, 900)
        ->assertSee('Thread root message');

    $page->click('[data-test=thread-summary]')
        ->assertPresent('[data-test=thread-panel]')
        ->assertVisible('[data-test=thread-close]')
        // The side pane keeps its fixed width beside the channel rather than
        // covering it: the masthead's title stays on screen to its left.
        ->assertScript(<<<'JS'
        (() => {
            const panel = document.querySelector('[data-test=thread-panel]').getBoundingClientRect();
            const heading = document.querySelector('header h1').getBoundingClientRect();

            return panel.width < 420 && heading.right <= panel.left;
        })()
        JS, true)
        ->assertScript(<<<'JS'
        (() => {
            const back = document.querySelector('[data-test=thread-back]');

            return back === null || back.offsetParent === null;
        })()
        JS, true)
        // The desktop header keeps the count, so no divider splits the list.
        ->assertNotPresent('[data-test=thread-replies-divider]');
})->with([
    'the breakpoint itself' => [768],
    'desktop' => [1024],
]);

test('back returns to the channel at the scroll position it was left at', function (): void {
    ['owner' => $alice] = threadedChannel();

    $page = signInThroughBrowser($alice)
        ->resize(390, 844)
        ->assertSee('Thread root message');

    // Let the freshly opened channel settle at its bottom pin first: the
    // virtualized timeline keeps re-pinning to the newest row until off-screen
    // heights are measured (#500's regime), and a scroll-up issued before that
    // glue releases is snapped back to the bottom.
    $page->assertScript(<<<'JS'
    (() => {
        const timeline = document.querySelector('[data-test="message-history"]');

        return timeline.scrollHeight - timeline.clientHeight - timeline.scrollTop <= 2;
    })()
    JS, true)
        ->wait(1);

    // Scroll the channel up to its top, where the thread root lives — the
    // position that must survive the push.
    $page->script(<<<'JS'
    () => {
        const timeline = document.querySelector('[data-test="message-history"]');

        timeline.scrollTop = 0;
        timeline.dispatchEvent(new Event('scroll'));
    }
    JS);

    // The position must *hold* before the thread opens — a late initial-pin
    // would otherwise snap the timeline back down and void the round trip this
    // test is about, so it is re-checked across a beat.
    $page->assertScript(
        '(() => document.querySelector(\'[data-test="message-history"]\').scrollTop === 0)()',
        true,
    )
        ->wait(1)
        ->assertScript(
            '(() => document.querySelector(\'[data-test="message-history"]\').scrollTop === 0)()',
            true,
        )
        ->click('[data-test=thread-summary]')
        ->assertVisible('[data-test=thread-back]')
        ->assertSee('Thread reply 2')
        ->click('[data-test=thread-back]')
        ->assertNotPresent('[data-test=thread-panel]')
        // Still at the top — not re-pinned to the newest message...
        ->assertScript(<<<'JS'
        (() => document.querySelector('[data-test="message-history"]').scrollTop <= 4)()
        JS, true)
        // ...which is only meaningful because the timeline genuinely scrolls.
        ->assertScript(<<<'JS'
        (() => {
            const timeline = document.querySelector('[data-test="message-history"]');

            return timeline.scrollHeight - timeline.clientHeight > 300;
        })()
        JS, true);
});

test('the header survives a long channel name without clipping the overflow menu', function (): void {
    ['owner' => $alice, 'channel' => $channel] = threadedChannel();

    $channel->update(['name' => 'quarterly-planning-and-roadmap-review-working-group']);

    signInThroughBrowser($alice)
        ->resize(360, 740)
        ->assertSee('Thread root message')
        ->click('[data-test=thread-summary]')
        ->assertVisible('[data-test=thread-back]')
        // The overflow menu stays wholly on screen...
        ->assertScript(<<<'JS'
        (() => {
            const options = document.querySelector('[data-test=thread-options]').getBoundingClientRect();

            return options.right <= window.innerWidth && options.left >= 0 && options.width >= 36;
        })()
        JS, true)
        // ...because the channel sub-line gave way, ellipsised on one line.
        ->assertScript(<<<'JS'
        (() => {
            const subline = document.querySelector('[data-test=thread-reply-count]');

            return subline.scrollWidth > subline.clientWidth;
        })()
        JS, true);
});

test('a reply arriving while the pushed view is open appears without a refresh', function (): void {
    ['owner' => $alice, 'member' => $bob] = threadedChannel();

    $alicePage = signInThroughBrowser($alice)
        ->resize(390, 844)
        ->assertSee('Thread root message');

    $alicePage->click('[data-test=thread-summary]')
        ->assertVisible('[data-test=thread-back]')
        ->assertSee('Thread reply 2');

    // Bob replies into the same thread from his own client.
    $bobPage = signInThroughBrowser($bob)->assertSee('Thread root message');

    $bobPage->click('[data-test=thread-summary]')
        ->assertPresent('[data-test=thread-panel]')
        ->assertSee('Thread reply 2');

    $body = 'Live reply while pushed';

    $bobPage->type('[data-test=thread-panel] [data-test=message-composer-input]', $body)
        ->click('[data-test=thread-panel] [data-test=message-composer-send]')
        ->assertSee($body);

    // Alice's full-screen push receives it over Reverb, no reload.
    $alicePage->assertSee($body);
});

test('the push holds together in French at the tightest width', function (): void {
    ['owner' => $alice] = threadedChannel();
    $alice->update(['locale' => AppLocale::French]);

    signInThroughBrowser($alice)
        ->resize(360, 740)
        ->assertSee('Thread root message')
        ->click('[data-test=thread-summary]')
        ->assertVisible('[data-test=thread-back]')
        ->assertSee('Fil de discussion')
        // French copy doesn't push anything past the viewport edge.
        ->assertScript(
            '(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)()',
            true,
        )
        ->assertScript(<<<'JS'
        (() => [...document.querySelectorAll('[data-test]')]
            .every(el => el.getBoundingClientRect().right <= window.innerWidth + 1))()
        JS, true)
        // The French placeholder stays on one line: a wrapped placeholder
        // measures two line-heights and swells the pill.
        ->assertScript(<<<'JS'
        (() => {
            const field = document.querySelector('[data-test=thread-panel] [data-test=message-composer-input]');

            return Math.round(field.getBoundingClientRect().height) <= 32;
        })()
        JS, true);
});
