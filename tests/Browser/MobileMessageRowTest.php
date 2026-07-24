<?php

declare(strict_types=1);

use App\Enums\AttachmentStatus;
use App\Models\Attachment;
use App\Models\Channel;
use App\Models\Message;
use App\Models\MessageReaction;
use App\Models\Poll;
use App\Models\User;

/**
 * The message row below `md` (design `Mobile Message List.dc.html`, screen `1c`).
 *
 * The desktop row was carried onto the phone unchanged: a 64px avatar gutter, a
 * vertical rail and an 18px inset spent 123px of a 390px screen on chrome, so
 * cards and poll options wrapped mid-phrase and roughly three messages fit per
 * screen. Below the breakpoint the gutter slims to 36px (a 26px avatar plus a
 * 10px gap), the rail and inset go, the group timestamp moves inline beside the
 * author name, and rich cards break out of the gutter to run edge to edge.
 *
 * From `md` up nothing moves — the desktop rhythm is unchanged, which is what
 * the last test in this file pins.
 */

/**
 * Seed #general with the mix the design draws: an author burst of short
 * messages, a closed two-option poll whose labels are the design's own, a
 * reply quote, reactions and a thread summary.
 *
 * @return array{owner: User, member: User, channel: Channel, poll: Message, root: Message}
 */
function mobileRowChannel(): array
{
    ['owner' => $alice, 'member' => $bob, 'channel' => $channel] = browserTeamWithChannel();

    $root = Message::factory()->for($channel)->for($alice)->create([
        'body' => 'Offsite logistics doc is updated.',
        'created_at' => now()->subMinutes(90),
        'reply_count' => 2,
        'last_reply_at' => now()->subMinutes(80),
    ]);

    foreach ([1, 2] as $i) {
        Message::factory()->for($channel)->inThread($root)->create([
            'user_id' => $bob->id,
            'body' => "Thread reply {$i}",
            'created_at' => now()->subMinutes(80)->addSeconds($i),
        ]);
    }

    MessageReaction::factory()->for($root)->for($bob)->emoji('🎉')->create();

    // A burst of short one-line messages from one author: the grouped
    // continuation rows whose left edge has to stay put.
    foreach (range(1, 6) as $i) {
        Message::factory()->for($channel)->for($alice)->create([
            'body' => "Short line {$i}",
            'created_at' => now()->subMinutes(70)->addSeconds($i),
        ]);
    }

    Message::factory()->for($channel)->for($bob)->replyTo($root)->create([
        'body' => 'Agreed, let us vote on it.',
        'created_at' => now()->subMinutes(60),
    ]);

    $withFiles = Message::factory()->for($channel)->for($alice)->create([
        'body' => 'Venue photo and the signed quote.',
        'created_at' => now()->subMinutes(55),
    ]);

    Attachment::factory()->create([
        'message_id' => $withFiles->id,
        'channel_id' => $channel->id,
        'user_id' => $alice->id,
        'status' => AttachmentStatus::Attached,
        'original_filename' => 'venue.png',
        'width' => 1200,
        'height' => 800,
    ]);

    Attachment::factory()->create([
        'message_id' => $withFiles->id,
        'channel_id' => $channel->id,
        'user_id' => $alice->id,
        'status' => AttachmentStatus::Attached,
        'mime_type' => 'application/pdf',
        'original_filename' => 'offsite-quote.pdf',
        'width' => null,
        'height' => null,
    ]);

    $pollMessage = Message::factory()->for($channel)->for($alice)->poll()->create([
        'created_at' => now()->subMinutes(50),
    ]);

    Poll::factory()->for($pollMessage, 'message')->closed()->withOptions([
        'Bold serif',
        'Minimal sans',
    ])->create(['question' => 'Which logo direction do you prefer?']);

    return [
        'owner' => $alice,
        'member' => $bob,
        'channel' => $channel,
        'poll' => $pollMessage,
        'root' => $root,
    ];
}

/**
 * Measures one author group's horizontal budget: the avatar box, the text
 * column, and the rail that used to sit between them.
 */
const MEASURE_GUTTER = <<<'JS'
(() => {
    const group = [...document.querySelectorAll('[data-test=message-group]')]
        .find((el) => el.querySelector('[data-test=message-body]'));
    const avatar = group.querySelector('[data-test=message-avatar]').getBoundingClientRect();
    const column = group.querySelector('[data-test=message-column]');
    const body = group.querySelector('[data-test=message-body]').getBoundingClientRect();

    return {
        avatarWidth: Math.round(avatar.width),
        gutter: Math.round(body.left - group.getBoundingClientRect().left),
        textWidth: Math.round(body.width),
        railWidth: getComputedStyle(column).borderLeftWidth,
        columnPaddingLeft: getComputedStyle(column).paddingLeft,
    };
})()
JS;

test('below md the avatar gutter is 36px with no rail and no inset', function (): void {
    ['owner' => $alice] = mobileRowChannel();

    $page = signInThroughBrowser($alice)
        ->resize(390, 844)
        ->assertSee('Which logo direction do you prefer?');

    $measurements = $page->script(MEASURE_GUTTER);

    expect($measurements['avatarWidth'])->toBe(26)
        ->and($measurements['gutter'])->toBe(36)
        ->and($measurements['railWidth'])->toBe('0px')
        ->and($measurements['columnPaddingLeft'])->toBe('0px');

    // The freed width lands in the text column: the desktop row left 249px of a
    // 390px screen for the message itself, this one clears 290px.
    expect($measurements['textWidth'])->toBeGreaterThanOrEqual(290);
});

test('below md the group timestamp rides beside the author name', function (): void {
    ['owner' => $alice] = mobileRowChannel();

    signInThroughBrowser($alice)
        ->resize(390, 844)
        ->assertSee('Which logo direction do you prefer?')
        // The inline stamp shows on every group; nothing is stacked under an
        // avatar any more.
        ->assertScript(<<<'JS'
        (() => {
            const inline = [...document.querySelectorAll('[data-test=message-group-time-inline]')];
            const stacked = [...document.querySelectorAll('[data-test=message-group-time]')];

            return inline.length > 0
                && inline.every((el) => el.offsetParent !== null)
                && stacked.every((el) => el.offsetParent === null);
        })()
        JS, true)
        // ...and the inline stamp sits on the author's own line, to its right.
        ->assertScript(<<<'JS'
        (() => {
            const name = document.querySelector('[data-test=message-author-name]').getBoundingClientRect();
            const time = document.querySelector('[data-test=message-group-time-inline]').getBoundingClientRect();

            return time.left >= name.right && Math.abs(time.bottom - name.bottom) <= 4;
        })()
        JS, true)
        // The hover-only per-message stamp cannot be revealed by touch, so it
        // stops competing for the 36px gutter.
        ->assertScript(<<<'JS'
        (() => [...document.querySelectorAll('[data-test=message-hover-time]')]
            .every((el) => el.offsetParent === null))()
        JS, true);
});

test('below md rich cards break out of the gutter and run edge to edge', function (): void {
    ['owner' => $alice] = mobileRowChannel();

    $page = signInThroughBrowser($alice)
        ->resize(390, 844)
        ->assertSee('Which logo direction do you prefer?');

    // The poll spans the timeline's full width — not the 36px-indented text
    // column — and trades its rounded box for hairline top and bottom rules.
    $page->assertScript(<<<'JS'
    (() => {
        const timeline = document.querySelector('[data-test="message-history"]').getBoundingClientRect();
        const card = document.querySelector('[data-test=poll-card]');
        const rect = card.getBoundingClientRect();
        const style = getComputedStyle(card);

        return Math.abs(rect.left - timeline.left) <= 1
            && Math.abs(rect.right - timeline.right) <= 1
            && style.borderTopLeftRadius === '0px'
            && style.borderLeftWidth === '0px'
            && style.borderRightWidth === '0px'
            && style.borderTopWidth !== '0px'
            && style.borderBottomWidth !== '0px';
    })()
    JS, true)
        // The quoted reply breaks out with it.
        ->assertScript(<<<'JS'
        (() => {
            const timeline = document.querySelector('[data-test="message-history"]').getBoundingClientRect();
            const quote = document.querySelector('[data-test=message-quote]').getBoundingClientRect();

            return Math.abs(quote.left - timeline.left) <= 1;
        })()
        JS, true)
        // An image and a download card break out too.
        ->assertScript(<<<'JS'
        (() => {
            const timeline = document.querySelector('[data-test="message-history"]').getBoundingClientRect();
            const file = document.querySelector('[data-test=attachment-file]');
            const image = document.querySelector('[data-test=attachment-image]').parentElement;

            return [file, image].every((el) => {
                const rect = el.getBoundingClientRect();
                const style = getComputedStyle(el);

                return Math.abs(rect.left - timeline.left) <= 1
                    && Math.abs(rect.right - timeline.right) <= 1
                    && style.borderTopLeftRadius === '0px'
                    && style.borderLeftWidth === '0px';
            });
        })()
        JS, true)
        // Breaking out never pushes the page sideways.
        ->assertScript(
            '(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)()',
            true,
        );
});

test('below md a small image is not upscaled by the break-out', function (): void {
    ['owner' => $alice, 'channel' => $channel] = mobileRowChannel();

    $message = Message::factory()->for($channel)->for($alice)->create([
        'body' => 'A tiny badge, not a photo.',
        'created_at' => now()->subMinutes(5),
    ]);

    Attachment::factory()->create([
        'message_id' => $message->id,
        'channel_id' => $channel->id,
        'user_id' => $alice->id,
        'status' => AttachmentStatus::Attached,
        'original_filename' => 'badge.png',
        'width' => 120,
        'height' => 80,
    ]);

    signInThroughBrowser($alice)
        ->resize(390, 844)
        ->assertSee('A tiny badge, not a photo.')
        // It holds its stored 120px and stays in the text column rather than
        // stretching into a blurry band against the screen edge.
        ->assertScript(<<<'JS'
        (() => {
            const body = [...document.querySelectorAll('[data-test=message-body]')]
                .find((el) => el.textContent.includes('A tiny badge'));
            const box = body.closest('[role=listitem]')
                .querySelector('[data-test=attachment-image]').parentElement
                .getBoundingClientRect();

            return Math.round(box.width) === 120
                && Math.abs(box.left - body.getBoundingClientRect().left) <= 1;
        })()
        JS, true);
});

test('below md a two-option poll keeps every label and its footer on one line', function (): void {
    ['owner' => $alice] = mobileRowChannel();

    signInThroughBrowser($alice)
        ->resize(360, 740)
        ->assertSee('Which logo direction do you prefer?')
        // Each label fits its line rather than wrapping mid-phrase, and each row
        // is a 44px touch target.
        ->assertScript(<<<'JS'
        (() => {
            const options = [...document.querySelectorAll('[data-test=poll-option]')];

            return options.length === 2 && options.every((option) => {
                const label = option.querySelector('[data-test=poll-option-label]');
                const lineHeight = parseFloat(getComputedStyle(label).lineHeight);

                return label.getBoundingClientRect().height <= lineHeight + 1
                    && option.getBoundingClientRect().height >= 44;
            });
        })()
        JS, true)
        // "4 votes · Closed" stays on one line too.
        ->assertScript(<<<'JS'
        (() => {
            const footer = document.querySelector('[data-test=poll-total]').parentElement;
            const lineHeight = parseFloat(getComputedStyle(footer).lineHeight);

            return footer.getBoundingClientRect().height <= lineHeight + 1;
        })()
        JS, true);
});

test('below md grouped continuations, reactions and the thread pill hold the 36px indent', function (): void {
    ['owner' => $alice] = mobileRowChannel();

    signInThroughBrowser($alice)
        ->resize(390, 844)
        ->assertSee('Short line 6')
        ->assertScript(<<<'JS'
        (() => {
            const lead = document.querySelector('[data-test=message-author-name]').getBoundingClientRect();
            const bodies = [...document.querySelectorAll('[data-test=message-body]')];
            const reactions = [...document.querySelectorAll('[data-test=reaction-pill]')];
            const threads = [...document.querySelectorAll('[data-test=thread-summary]')];

            return bodies.length > 1
                && [...bodies, ...reactions, ...threads]
                    .every((el) => Math.abs(el.getBoundingClientRect().left - lead.left) <= 1);
        })()
        JS, true)
        // The typing line is indented to the same text column.
        ->assertScript(<<<'JS'
        (() => {
            const lead = document.querySelector('[data-test=message-author-name]').getBoundingClientRect();
            const typing = document.querySelector('[data-test=typing-indicator]');
            const inset = typing.getBoundingClientRect().left
                + parseFloat(getComputedStyle(typing).paddingLeft);

            return Math.abs(inset - lead.left) <= 1;
        })()
        JS, true);
});

test('below md a sentence costs fewer lines and bursts sit closer together', function (): void {
    ['owner' => $alice, 'member' => $bob, 'channel' => $channel] = mobileRowChannel();

    // A realistic back-and-forth: sentence-length messages from two authors, so
    // every row pays for an avatar and a name. Fitting more of these per screen
    // is what the redesign is for, and it comes from two places — the text
    // column stopped forcing a third line, and bursts sit 14px apart, not 18px.
    foreach (range(1, 12) as $i) {
        Message::factory()->for($channel)->for($i % 2 === 0 ? $alice : $bob)->create([
            'body' => "Message {$i}: the venue quote came back a little over budget.",
            'created_at' => now()->subMinutes(20)->addSeconds($i),
        ]);
    }

    $page = signInThroughBrowser($alice)
        ->resize(390, 844)
        ->assertSee('the venue quote came back');

    $rhythm = $page->script(<<<'JS'
    (() => {
        const bodies = [...document.querySelectorAll('[data-test=message-body]')]
            .filter((el) => el.textContent.includes('the venue quote came back'));
        const body = bodies[bodies.length - 1];
        const style = getComputedStyle(body);
        const padding = parseFloat(style.paddingTop) + parseFloat(style.paddingBottom);
        const group = body.closest('[data-test=message-group]');

        return {
            lines: Math.round((body.getBoundingClientRect().height - padding) / parseFloat(style.lineHeight)),
            burstGap: parseFloat(getComputedStyle(group).marginTop),
        };
    })()
    JS);

    expect($rhythm['lines'])->toBe(2)
        ->and($rhythm['burstGap'])->toEqual(14);
});

test('a tall channel still lands on the newest message at a phone width', function (): void {
    ['owner' => $alice, 'member' => $bob, 'channel' => $channel] = mobileRowChannel();

    // The slimmer row measures shorter than the virtualizer's desktop-derived
    // 84px estimate, which widens the estimate-to-real gap that used to strand
    // the initial auto-pin short of the newest message (#500). Tall rows on a
    // short viewport are the worst case for it.
    $filler = str_repeat("lorem ipsum dolor sit amet consectetur\n", 4);
    $newest = 60;

    foreach (range(1, $newest) as $i) {
        Message::factory()->for($channel)->for($i % 2 === 0 ? $alice : $bob)->create([
            'body' => "Row {$i}\n{$filler}",
            'created_at' => now()->subMinutes(20)->addSeconds($i),
        ]);
    }

    $page = signInThroughBrowser($alice)
        ->resize(390, 844)
        ->assertSee("Row {$newest}")
        ->wait(2);

    // The newest row sits at the foot of the viewport, not a screen above it.
    $page->assertScript(<<<'JS'
    (() => {
        const timeline = document.querySelector('[data-test=message-history]');
        const rows = [...timeline.querySelectorAll('[id^="message-"]')];
        const last = rows[rows.length - 1];

        if (!last) {
            return false;
        }

        const container = timeline.getBoundingClientRect();
        const row = last.getBoundingClientRect();

        return row.top < container.bottom
            && row.bottom > container.top
            && Math.abs(container.bottom - row.bottom) <= 120;
    })()
    JS, true)
        ->assertNotPresent('[data-test=jump-to-latest]');

    // Jumping to an old message lands it on screen rather than short of it.
    $page->script(<<<'JS'
    () => {
        const timeline = document.querySelector('[data-test=message-history]');

        timeline.scrollTop = 0;
        timeline.dispatchEvent(new Event('scroll'));
    }
    JS);

    // The scroll holds where it was put — a late pin would snap it back — and
    // history renders into the window rather than leaving it blank, which is
    // what a badly-drifted row estimate looks like.
    $page->wait(1)
        ->assertScript(<<<'JS'
        (() => {
            const timeline = document.querySelector('[data-test=message-history]');
            const rows = [...timeline.querySelectorAll('[id^="message-"]')];
            const container = timeline.getBoundingClientRect();

            return timeline.scrollTop <= 4 && rows.some((row) => {
                const rect = row.getBoundingClientRect();

                return rect.top < container.bottom && rect.bottom > container.top;
            });
        })()
        JS, true);
});

test('the thread push inherits the slim row', function (): void {
    ['owner' => $alice] = mobileRowChannel();

    $page = signInThroughBrowser($alice)
        ->resize(390, 844)
        ->assertSee('Offsite logistics doc is updated.');

    $page->click('[data-test=thread-summary]')
        ->assertVisible('[data-test=thread-back]')
        ->assertSee('Thread reply 2');

    $measurements = $page->script(MEASURE_GUTTER);

    expect($measurements['avatarWidth'])->toBe(26)
        ->and($measurements['gutter'])->toBe(36)
        ->and($measurements['railWidth'])->toBe('0px');
});

test('from md up the row keeps the desktop gutter, rail and stacked timestamp', function (int $width): void {
    ['owner' => $alice] = mobileRowChannel();

    $page = signInThroughBrowser($alice)
        ->resize($width, 900)
        ->assertSee('Which logo direction do you prefer?')
        // The stacked stamp is back under the avatar and the inline one is gone.
        ->assertScript(<<<'JS'
        (() => {
            const stacked = [...document.querySelectorAll('[data-test=message-group-time]')];
            const inline = [...document.querySelectorAll('[data-test=message-group-time-inline]')];

            return stacked.length > 0
                && stacked.every((el) => el.offsetParent !== null)
                && inline.every((el) => el.offsetParent === null);
        })()
        JS, true)
        // The poll keeps its rounded, bordered card inside the text column.
        ->assertScript(<<<'JS'
        (() => {
            const timeline = document.querySelector('[data-test="message-history"]').getBoundingClientRect();
            const card = document.querySelector('[data-test=poll-card]');
            const style = getComputedStyle(card);

            return card.getBoundingClientRect().left > timeline.left + 60
                && style.borderTopLeftRadius !== '0px'
                && style.borderLeftWidth !== '0px';
        })()
        JS, true);

    $measurements = $page->script(MEASURE_GUTTER);

    expect($measurements['avatarWidth'])->toBe(34)
        ->and($measurements['gutter'])->toBe(83)
        ->and($measurements['railWidth'])->toBe('1px')
        ->and($measurements['columnPaddingLeft'])->toBe('18px');
})->with([
    'the md boundary' => [768],
    'wide desktop' => [1280],
]);
