<?php

declare(strict_types=1);

/**
 * The composer's phone layout, at the only level a browser can settle it: the
 * measured geometry of the two targets in the control row, and the sheet the
 * leading one raises actually opening and dismissing against the real dialog
 * primitive. Everything conditional about the sheet's contents is covered far
 * more cheaply in MessageComposer.mobileControls.test.ts.
 */
test('the composer offers two touch targets and no more on a phone', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    signInThroughBrowser($alice)
        ->resize(390, 844)
        ->navigate(browserChannelUrl($team, $channel))
        // The attach disc is 44pt and the primary disc 48pt, the sizes the
        // design draws them at.
        ->assertScript(<<<'JS'
        (() => {
            const box = selector => document.querySelector(selector).getBoundingClientRect();
            const attach = box('[data-test="composer-attach-sheet-toggle"]');
            const disc = box('[data-test="message-composer-record"]');

            return Math.round(attach.width) >= 44 && Math.round(attach.height) >= 44
                && Math.round(disc.width) >= 48 && Math.round(disc.height) >= 48;
        })()
        JS, true)
        // The split pill and the inline tool cluster belong to the desktop
        // layout and must not be rendered here at all.
        ->assertMissing('@message-composer-schedule')
        ->assertMissing('@composer-tools');
});

test('the primary disc holds its place across the first keystroke', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    // The whole point of morphing one element rather than swapping two: the
    // field's right edge is where it was, so nothing under the thumb moves.
    signInThroughBrowser($alice)
        ->resize(390, 844)
        ->navigate(browserChannelUrl($team, $channel))
        ->script(<<<'JS'
        window.__composerBefore = (() => {
            const field = document.querySelector('[data-test="message-composer-input"]').getBoundingClientRect();
            const disc = document.querySelector('[data-test="message-composer-record"]').getBoundingClientRect();

            return { right: Math.round(field.right), x: Math.round(disc.x), width: Math.round(disc.width) };
        })()
        JS)
        ->type('@message-composer-input', 'a')
        ->assertPresent('@message-composer-send')
        ->assertScript(<<<'JS'
        (() => {
            const before = window.__composerBefore;
            const field = document.querySelector('[data-test="message-composer-input"]').getBoundingClientRect();
            const disc = document.querySelector('[data-test="message-composer-send"]').getBoundingClientRect();

            return Math.round(field.right) === before.right
                && Math.round(disc.x) === before.x
                && Math.round(disc.width) === before.width;
        })()
        JS, true);
});

test('the attach sheet closes by the x it opened from', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    signInThroughBrowser($alice)
        ->resize(390, 844)
        ->navigate(browserChannelUrl($team, $channel))
        ->click('@composer-attach-sheet-toggle')
        ->assertVisible('@composer-attach-sheet')
        ->assertAttribute('@composer-attach-sheet-toggle', 'aria-expanded', 'true')
        ->click('@composer-attach-sheet-toggle')
        ->assertMissing('@composer-attach-sheet');
});

test('dragging the attach sheet down by its handle throws it away', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    $page = signInThroughBrowser($alice)
        ->resize(390, 844)
        ->navigate(browserChannelUrl($team, $channel))
        ->click('@composer-attach-sheet-toggle')
        ->assertPresent('@sheet-grab-handle');

    // Synthesised as touch on purpose: the gesture is bound to touch and pen, so
    // a mouse press on the handle never starts a drag on a desktop.
    $page->script(<<<'JS'
    (() => {
        const handle = document.querySelector('[data-test="sheet-grab-handle"]');
        const box = handle.getBoundingClientRect();
        const at = (type, clientY) => new PointerEvent(type, {
            pointerId: 1,
            pointerType: 'touch',
            isPrimary: true,
            bubbles: true,
            cancelable: true,
            clientX: Math.round(box.x + box.width / 2),
            clientY,
        });

        handle.setPointerCapture = () => {};
        handle.hasPointerCapture = () => false;

        handle.dispatchEvent(at('pointerdown', box.y));
        handle.dispatchEvent(at('pointermove', box.y + 300));
        handle.dispatchEvent(at('pointerup', box.y + 300));
    })()
    JS);

    $page->assertMissing('@composer-attach-sheet');
});
