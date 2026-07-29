<?php

declare(strict_types=1);

use App\Enums\AppLocale;
use App\Models\Message;
use App\Models\MessagePin;
use App\Models\User;

/**
 * A row of the pins panel (`PinsPanel.vue`).
 *
 * The row used to float its Unpin pill over itself — `absolute -top-1 right-3`,
 * revealed only by `:hover`, with the attribution line holding a hardcoded
 * `mr-24` to keep out of its way. That reserve was sized around the English
 * "Unpin", so the French "Désépingler" painted straight over "Aller →"; hover is
 * also the one input a keyboard and a touchscreen do not have, so the panel's own
 * Unpin was unreachable from either. The control now owns a grid track of the
 * row, so its real width is the reserve, and it comes back on focus as well as
 * hover (#999).
 *
 * @return array{owner: User, pinner: User, message: Message}
 */
function pinnedRowChannel(string $pinnerName = 'Bob Member'): array
{
    ['owner' => $alice, 'member' => $bob, 'channel' => $channel] = browserTeamWithChannel();

    $bob->update(['name' => $pinnerName]);

    $message = Message::factory()->for($channel)->for($bob)->create([
        'body' => 'hi!',
        'created_at' => now()->subMinutes(5),
    ]);

    MessagePin::factory()->create([
        'message_id' => $message->id,
        'channel_id' => $channel->id,
        'pinned_by' => $bob->id,
    ]);

    return ['owner' => $alice, 'pinner' => $bob, 'message' => $message];
}

/**
 * A script asserting the Unpin control and the Jump label are both fully drawn
 * and share no pixel, and that the pill stays within its own row.
 */
function unpinClearsJump(): string
{
    return <<<'JS'
    (() => {
        const entry = document.querySelector('[data-test="pins-panel-entry"]');
        const jump = document.querySelector('[data-test="pins-panel-jump"]');
        const unpin = document.querySelector('[data-test="pins-panel-unpin"]');

        const drawn = (element) => {
            const box = element.getBoundingClientRect();

            return box.width > 0
                && box.height > 0
                && getComputedStyle(element).opacity === '1'
                && element.scrollWidth <= element.clientWidth + 1;
        };

        const jumpBox = jump.getBoundingClientRect();
        const unpinBox = unpin.getBoundingClientRect();
        const entryBox = entry.getBoundingClientRect();

        const overlaps = jumpBox.left < unpinBox.right
            && unpinBox.left < jumpBox.right
            && jumpBox.top < unpinBox.bottom
            && unpinBox.top < jumpBox.bottom;

        return drawn(jump)
            && drawn(unpin)
            && ! overlaps
            // The pill hung 4px above its row and overlapped the neighbour above.
            && unpinBox.top >= entryBox.top - 0.5
            && unpinBox.bottom <= entryBox.bottom + 0.5;
    })()
    JS;
}

/**
 * A script asserting the attribution line still reads as one line: the pinned-by
 * name is the only part that gives, clipped to an ellipsis rather than wrapped,
 * and the separator, timestamp and Jump label all sit on its centre line.
 */
function attributionStaysOnOneLine(): string
{
    return <<<'JS'
    (() => {
        const name = document.querySelector('[data-test="pins-panel-attribution"]');
        const line = name.parentElement;
        const lineBox = line.getBoundingClientRect();

        const onTheLine = (element) => {
            const box = element.getBoundingClientRect();

            return Math.abs(
                (box.top + box.height / 2) - (lineBox.top + lineBox.height / 2),
            ) <= 1;
        };

        const drawnParts = [...line.children].filter(
            (part) => part.getClientRects().length > 0,
        );

        return name.getClientRects().length === 1
            && drawnParts.length >= 3
            && drawnParts.every(onTheLine)
            && line.scrollWidth <= line.clientWidth + 1;
    })()
    JS;
}

test('the pins panel unpin control is reachable by keyboard alone', function (): void {
    ['owner' => $alice] = pinnedRowChannel();

    $page = signInThroughBrowser($alice)
        ->click('@masthead-pins')
        ->assertPresent('@pins-panel-unpin');

    suppressTransitions($page)
        ->assertNoAccessibilityIssues()
        // Tabbing off the row reaches the Unpin control — it was `hidden` until
        // hover, which takes it out of the tab order altogether.
        ->keys('@pins-panel-row', ['Tab'])
        ->assertScript(
            'document.activeElement.dataset.test === "pins-panel-unpin"',
            true,
        )
        // ...and focus alone, with no pointer anywhere near the row, draws it.
        ->assertScript(
            'getComputedStyle(document.querySelector(\'[data-test="pins-panel-unpin"]\')).opacity',
            '1',
        )
        // Activating it from the keyboard unpins: the panel's last row goes.
        ->keys('@pins-panel-unpin', ['Enter'])
        ->assertPresent('@pins-panel-empty')
        ->assertMissing('@pins-panel-row');
});

test('the hovered row keeps the French unpin label clear of Jump', function (): void {
    ['owner' => $alice] = pinnedRowChannel();
    $alice->update(['locale' => AppLocale::French]);

    // See #764 — the French catalog only reaches the client on a document load.
    $page = signInThroughBrowser($alice)
        ->refresh()
        ->click('@masthead-pins')
        ->assertPresent('@pins-panel-row');

    suppressTransitions($page)
        ->hover('@pins-panel-row')
        ->assertSee('Désépingler')
        ->assertSee('Aller')
        ->assertScript(unpinClearsJump(), true)
        ->assertScript(attributionStaysOnOneLine(), true);
});

test('a long pinned-by name ellipsises instead of wrapping the line', function (): void {
    // Longer than any translation of "Pinned by" could make up for, so the guard
    // holds for every locale rather than for the one that exposed it.
    ['owner' => $alice] = pinnedRowChannel('Alexandra Featherstonehaugh-Marchbanks');

    $page = signInThroughBrowser($alice)
        ->click('@masthead-pins')
        ->assertPresent('@pins-panel-row');

    suppressTransitions($page)
        ->hover('@pins-panel-row')
        ->assertScript(attributionStaysOnOneLine(), true)
        ->assertScript(<<<'JS'
        (() => {
            const name = document.querySelector('[data-test="pins-panel-attribution"]');

            return name.scrollWidth > name.clientWidth
                && getComputedStyle(name).textOverflow === 'ellipsis';
        })()
        JS, true)
        // The author line under it is the same shape and gives the same way: the
        // name is clipped rather than wrapped over the timestamp beside it.
        ->assertScript(<<<'JS'
        (() => {
            const author = document.querySelector('[data-test="pins-panel-author"]');
            const sentAt = document.querySelector('[data-test="pins-panel-sent-at"]');

            return author.getClientRects().length === 1
                && sentAt.getClientRects().length === 1
                && author.scrollWidth > author.clientWidth
                && getComputedStyle(author).textOverflow === 'ellipsis';
        })()
        JS, true)
        ->assertScript(unpinClearsJump(), true);
});

test('the row holds together at the panel narrow clamp', function (): void {
    ['owner' => $alice] = pinnedRowChannel('Alexandra Featherstonehaugh-Marchbanks');
    $alice->update(['locale' => AppLocale::French]);

    // `max-w-[calc(100vw-3rem)]` clamps the panel to 312px here, which is the
    // width the row was never checked at.
    $page = signInThroughBrowser($alice)
        ->resize(360, 780)
        ->refresh()
        ->click('@masthead-pins')
        ->assertPresent('@pins-panel-row');

    suppressTransitions($page)
        // Nothing hovers a phone, so the control is drawn before any pointer
        // touches the row, and the Jump hint — which only hover could reveal —
        // is dropped rather than left holding width it cannot earn.
        ->assertScript(<<<'JS'
        (() => {
            const unpin = document.querySelector('[data-test="pins-panel-unpin"]');
            const jump = document.querySelector('[data-test="pins-panel-jump"]');

            return getComputedStyle(unpin).opacity === '1'
                && unpin.getBoundingClientRect().width > 0
                && jump.getClientRects().length === 0;
        })()
        JS, true)
        ->assertScript(attributionStaysOnOneLine(), true)
        ->assertScript(<<<'JS'
        (() => {
            const panel = document.querySelector('[data-test="pins-panel"]');
            const entry = document.querySelector('[data-test="pins-panel-entry"]');

            return panel.scrollWidth <= panel.clientWidth + 1
                && entry.scrollWidth <= entry.clientWidth + 1;
        })()
        JS, true);
});
