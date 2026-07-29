<?php

declare(strict_types=1);

/**
 * Focus restore when a dialog closes (#784).
 *
 * WCAG 2.4.3 (Focus Order): a keyboard or screen-reader user who opens a dialog
 * and closes it belongs back at the control they opened it from, not at the top
 * of the document with the whole page to tab through again.
 *
 * The defect was a timing one, and needs a real browser to see: reka restores
 * focus synchronously as the dialog tears down, while the page behind it is
 * still `inert` (the app mirrors `inert` onto whatever reka hides — see
 * `resources/js/lib/overlayInert.ts`). A browser answers a focus call into an
 * inert subtree by focusing nothing at all, so focus landed on `<body>`. jsdom
 * has no such rule, which is why this half of the cover lives here.
 */

/**
 * Whether focus has come home to the given `data-test` control. Read after the
 * dialog is gone, so it also proves the restore outlived the teardown.
 */
function focusIsOn(string $test): string
{
    return <<<JS
    (() => document.activeElement === document.querySelector('[data-test="{$test}"]'))()
    JS;
}

test('closing a dialog with Escape returns focus to the control that opened it', function (int $width, int $height): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    openCreateChannelDialog(
        signInThroughBrowser($alice),
        $width,
        $height,
        browserChannelUrl($team, $channel),
    )
        ->keys('@create-channel-name', ['Escape'])
        ->assertNotPresent('@create-channel-submit')
        ->assertScript(focusIsOn('create-channel-trigger'), true);
})->with([
    // Not a mobile defect: it held at both ends of the range, so both are read.
    'phone' => [390, 844],
    'desktop' => [1280, 800],
]);

test('closing a dialog with its close button returns focus too', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    openCreateChannelDialog(
        signInThroughBrowser($alice),
        1280,
        800,
        browserChannelUrl($team, $channel),
    )
        ->click('@dialog-close-button')
        ->assertNotPresent('@create-channel-submit')
        ->assertScript(focusIsOn('create-channel-trigger'), true);
});

test('dismissing a dialog by its scrim returns focus too', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    $page = openCreateChannelDialog(
        signInThroughBrowser($alice),
        390,
        844,
        browserChannelUrl($team, $channel),
    );

    // Dispatched at whatever a hit test says is under that point, not at the
    // overlay element: while a dialog is open the whole page is
    // `pointer-events: none`, so a tap above the sheet resolves to the document
    // and is answered by a document-level listener.
    $page->script(<<<'JS'
    (() => {
        const sheet = document.querySelector('[data-slot="dialog-content"]').getBoundingClientRect();
        const point = {
            bubbles: true,
            cancelable: true,
            clientX: Math.round(window.innerWidth / 2),
            clientY: Math.round(sheet.top / 2),
        };
        const target = document.elementFromPoint(point.clientX, point.clientY);
        const pointer = { ...point, pointerId: 1, pointerType: 'mouse', isPrimary: true, button: 0 };

        target.dispatchEvent(new PointerEvent('pointerdown', pointer));
        target.dispatchEvent(new PointerEvent('pointerup', pointer));
        target.dispatchEvent(new MouseEvent('click', { ...point, button: 0 }));
    })()
    JS);

    $page->assertNotPresent('@create-channel-submit')
        ->assertScript(focusIsOn('create-channel-trigger'), true);
});

test('a dialog opened from a dropdown menu returns focus to the menu item', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    // The one opener that is not a plain button: a menu item that keeps its menu
    // open while the dialog it triggers takes over.
    signInThroughBrowser($alice)
        ->resize(1280, 800)
        ->navigate(browserChannelUrl($team, $channel))
        ->click('@workspace-switcher')
        ->click('@team-switcher-new-team')
        ->assertPresent('@create-team-submit')
        ->keys('@create-team-name', ['Escape'])
        ->assertNotPresent('@create-team-submit')
        ->assertScript(focusIsOn('team-switcher-new-team'), true);
});
