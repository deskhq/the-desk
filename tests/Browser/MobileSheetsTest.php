<?php

declare(strict_types=1);

use App\Enums\AppLocale;
use Pest\Browser\Api\AwaitableWebpage;

/**
 * Dialogs below the `md` breakpoint (#772).
 *
 * Every dialog presents as a bottom sheet on a phone and stays a centred dialog
 * from `md` up. The defect this replaces was structural rather than cosmetic: a
 * dialog centred with `translate(-50%, -50%)` and no height cap overflows both
 * ends of a short viewport, so on a landscape phone its title and its primary
 * action were *both* off screen, with no way to scroll to either.
 */

/**
 * Whether the open dialog presents as a bottom sheet: pinned to the bottom edge,
 * spanning the full width, and no taller than the screen.
 */
function openSurfaceIsASheet(): string
{
    return <<<'JS'
    (() => {
        const sheet = document.querySelector('[data-slot="dialog-content"]');

        if (sheet === null) {
            return false;
        }

        const box = sheet.getBoundingClientRect();

        return Math.round(box.bottom) === window.innerHeight
            && Math.round(box.width) === window.innerWidth
            && box.top >= 0
            && box.height <= window.innerHeight;
    })()
    JS;
}

/**
 * Whether the open dialog presents as a centred desktop dialog, floating clear
 * of every edge.
 */
function openSurfaceIsACentredDialog(): string
{
    return <<<'JS'
    (() => {
        const dialog = document.querySelector('[data-slot="dialog-content"]');

        if (dialog === null) {
            return false;
        }

        const box = dialog.getBoundingClientRect();

        return box.bottom < window.innerHeight
            && box.top > 0
            && box.width < window.innerWidth;
    })()
    JS;
}

/**
 * Open the create-channel dialog from the dock. It is the plainest of the form
 * dialogs — a header, three fields and a pair of actions — so it stands in for
 * the whole set that shares the primitive.
 */
function openCreateChannelDialog(AwaitableWebpage $page, int $width, int $height, string $url): AwaitableWebpage
{
    $page = $page->resize($width, $height)->navigate($url);

    // Below the breakpoint the dock is a Sheet, so its rows only exist once it
    // is opened; from `md` up the rail is always mounted.
    if ($width < 768) {
        $page = $page->click('@sidebar-toggle');
    }

    return $page->click('@create-channel-trigger')->assertPresent('@create-channel-submit');
}

test('a dialog is a bottom sheet on a phone and a centred dialog from md up', function (int $width, int $height, bool $expectSheet): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    openCreateChannelDialog(
        signInThroughBrowser($alice),
        $width,
        $height,
        browserChannelUrl($team, $channel),
    )
        ->assertScript(openSurfaceIsASheet(), $expectSheet)
        ->assertScript(openSurfaceIsACentredDialog(), ! $expectSheet);
})->with([
    'small phone' => [360, 740, true],
    'iPhone SE' => [375, 667, true],
    'iPhone 14' => [390, 844, true],
    'large phone' => [430, 932, true],
    // A short viewport is its own failure mode, and where the old centred dialog
    // ran off both ends of the screen at once.
    'landscape phone' => [740, 360, true],
    // The breakpoint itself is desktop, matching `useIsMobile` and Tailwind's
    // `md:` — the boundary the dock got wrong in #771.
    'tablet portrait' => [768, 1024, false],
    'desktop' => [1280, 800, false],
]);

test('a sheet taller than the screen scrolls inside itself and leaves the page behind locked', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    // A landscape phone is 360px tall; the create-channel form is not.
    openCreateChannelDialog(
        signInThroughBrowser($alice),
        740,
        360,
        browserChannelUrl($team, $channel),
    )
        // The form is taller than the sheet, and scrolling the sheet — not the
        // page — is what brings its primary action within reach.
        ->assertScript(<<<'JS'
        (() => {
            const sheet = document.querySelector('[data-slot="dialog-content"]');

            return sheet.scrollHeight > sheet.clientHeight;
        })()
        JS, true)
        ->assertScript(<<<'JS'
        (() => {
            const sheet = document.querySelector('[data-slot="dialog-content"]');
            sheet.scrollTop = sheet.scrollHeight;

            const submit = document.querySelector('[data-test="create-channel-submit"]')
                .getBoundingClientRect();

            return submit.top >= 0 && submit.bottom <= window.innerHeight;
        })()
        JS, true)
        // ...and the conversation behind it does not scroll instead, which is
        // what makes an overflowing sheet feel like a broken page.
        ->assertScript(<<<'JS'
        (() => document.documentElement.scrollHeight <= document.documentElement.clientHeight)()
        JS, true);
});

test('a sheet keeps every control it draws inside the viewport', function (int $width, int $height): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    openCreateChannelDialog(
        signInThroughBrowser($alice),
        $width,
        $height,
        browserChannelUrl($team, $channel),
    )
        ->assertScript(<<<'JS'
        (() => {
            const sheet = document.querySelector('[data-slot="dialog-content"]');

            return [...sheet.querySelectorAll('button, input, select')]
                // A Select ships a visually-hidden native `<select>` parked off
                // the left edge to carry its value; it is not a drawn control.
                .filter(control => control.closest('[aria-hidden="true"]') === null)
                .every(control => {
                    const box = control.getBoundingClientRect();

                    return box.left >= -1 && box.right <= window.innerWidth + 1;
                });
        })()
        JS, true);
})->with([
    'small phone' => [360, 740],
    'iPhone SE' => [375, 667],
    'iPhone 14' => [390, 844],
    'large phone' => [430, 932],
]);

test('the close button is fully visible with nothing painted over it', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    // The defect (#803): the grab-handle strip is sticky with an opaque
    // background, and the close button sat underneath it — hit-testing a spread
    // of points inside the button proves the whole of it is on top, where a
    // class assertion could not.
    openCreateChannelDialog(
        signInThroughBrowser($alice),
        390,
        844,
        browserChannelUrl($team, $channel),
    )
        ->assertScript(<<<'JS'
        (() => {
            const close = document.querySelector('[data-test="dialog-close-button"]');

            if (close === null) {
                return false;
            }

            const box = close.getBoundingClientRect();

            if (box.top < 0 || box.left < 0
                || box.bottom > window.innerHeight || box.right > window.innerWidth) {
                return false;
            }

            // Inset past the button's 2px corner radius, so a corner sample
            // lands on the button rather than just outside its rounded shape.
            return [
                [box.left + 3, box.top + 3],
                [box.right - 3, box.top + 3],
                [box.left + 3, box.bottom - 3],
                [box.right - 3, box.bottom - 3],
                [box.left + box.width / 2, box.top + box.height / 2],
            ].every(([x, y]) => {
                const hit = document.elementFromPoint(x, y);

                return hit !== null && close.contains(hit);
            });
        })()
        JS, true);
});

test('the close button stays reachable while the sheet scrolls', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    // A landscape phone is 360px tall; the create-channel form is not. The
    // button used to be positioned against the scroll container's content, so
    // scrolling to the primary action carried the way out off the top (#803).
    openCreateChannelDialog(
        signInThroughBrowser($alice),
        740,
        360,
        browserChannelUrl($team, $channel),
    )
        ->assertScript(<<<'JS'
        (() => {
            const sheet = document.querySelector('[data-slot="dialog-content"]');
            sheet.scrollTop = sheet.scrollHeight;

            const close = document.querySelector('[data-test="dialog-close-button"]');
            const box = close.getBoundingClientRect();
            const hit = document.elementFromPoint(
                box.left + box.width / 2,
                box.top + box.height / 2,
            );

            return box.top >= 0
                && box.bottom <= window.innerHeight
                && hit !== null
                && close.contains(hit);
        })()
        JS, true);
});

test('a sheet traps focus, and Escape closes it', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    $page = openCreateChannelDialog(
        signInThroughBrowser($alice),
        390,
        844,
        browserChannelUrl($team, $channel),
    )
        // Focus lands inside the sheet when it opens...
        ->assertScript(<<<'JS'
        (() => document.querySelector('[data-slot="dialog-content"]').contains(document.activeElement))()
        JS, true);

    // ...and cannot be tabbed out of it: past the last control it wraps back to
    // the first rather than walking into the conversation behind the scrim.
    // Checked after every press, so a failure names the tab that escaped rather
    // than only reporting that one of twelve did.
    foreach (range(1, 12) as $ignored) {
        $page = $page
            ->keys('[data-slot="dialog-content"]', ['Tab'])
            ->assertScript(<<<'JS'
            (() => document.querySelector('[data-slot="dialog-content"]').contains(document.activeElement))()
            JS, true);
    }

    $page->keys('@create-channel-name', ['Escape'])
        ->assertNotPresent('@create-channel-submit');
});

test('tapping the scrim dismisses a sheet', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    // A sheet leaves the top of the screen showing, so — unlike the dock, whose
    // scrim the sheet covers entirely — there is somewhere to tap that really is
    // the scrim.
    $page = openCreateChannelDialog(
        signInThroughBrowser($alice),
        390,
        844,
        browserChannelUrl($team, $channel),
    );

    // Dispatched at whatever a hit test says is under that point, not at the
    // overlay element: while a dialog is open the whole page is
    // `pointer-events: none`, so a tap above the sheet resolves to the document
    // and is answered by a document-level listener. Aiming straight at the
    // overlay would pass without proving a finger ever reaches it.
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

        target.dispatchEvent(new PointerEvent('pointerdown', { ...point, pointerId: 1, pointerType: 'mouse', isPrimary: true, button: 0 }));
        target.dispatchEvent(new PointerEvent('pointerup', { ...point, pointerId: 1, pointerType: 'mouse', isPrimary: true, button: 0 }));
        target.dispatchEvent(new MouseEvent('click', { ...point, button: 0 }));
    })()
    JS);

    $page->assertNotPresent('@create-channel-submit');
});

test('dragging the grab handle down throws the sheet away', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    $page = openCreateChannelDialog(
        signInThroughBrowser($alice),
        390,
        844,
        browserChannelUrl($team, $channel),
    )->assertPresent('@sheet-grab-handle');

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

        // Pointer capture needs a trusted event. The handlers only use it to keep
        // receiving moves, which a synthesised drag delivers to them anyway.
        handle.setPointerCapture = () => {};
        handle.hasPointerCapture = () => false;

        handle.dispatchEvent(at('pointerdown', box.y));
        handle.dispatchEvent(at('pointermove', box.y + 300));
        handle.dispatchEvent(at('pointerup', box.y + 300));
    })()
    JS);

    $page->assertNotPresent('@create-channel-submit');
});

test('a detail sheet stands at 85% of the screen whatever it holds', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    // Reminders is a list that shortens as it is worked through; pinning it to
    // the epic's 85% keeps it from resizing under the thumb between taps.
    signInThroughBrowser($alice)
        ->resize(390, 844)
        ->navigate(browserChannelUrl($team, $channel))
        ->click('@sidebar-toggle')
        ->click('@reminders-trigger')
        ->assertScript(openSurfaceIsASheet(), true)
        ->assertScript(<<<'JS'
        (() => {
            const height = document.querySelector('[data-slot="dialog-content"]')
                .getBoundingClientRect().height;

            return Math.abs(height - window.innerHeight * 0.85) <= 1;
        })()
        JS, true);
});

test('a sheet survives a locale whose words run longer than the English', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();
    $alice->update(['locale' => AppLocale::French]);

    // French is where a tight row first breaks (#765), and 360px is the tightest
    // width the epic covers.
    openCreateChannelDialog(
        signInThroughBrowser($alice),
        360,
        740,
        browserChannelUrl($team, $channel),
    )
        // The catalog has actually taken: without this the sheet would be proven
        // against the English copy while claiming to prove it against French.
        ->assertSee('Créer un canal')
        ->assertScript(openSurfaceIsASheet(), true)
        ->assertScript(<<<'JS'
        (() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)()
        JS, true);
});

test('an open sheet has no serious accessibility violations in either theme', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    // The sheet adds chrome the centred dialog never had — a grab handle, a new
    // scrim token — and the automated gate does not audit a11y. axe-core reads
    // the real rendered DOM, so a clean run covers both the handle's semantics
    // (decorative, and unreachable by keyboard) and the scrim's contrast.
    // Settle first: the sheet slides and fades in over 200ms, and
    // `assertNoAccessibilityIssues` is one of the two assertions that does not
    // retry. Sampled mid-fade, axe reads the half-transparent title composited
    // against everything behind it and reports a contrast of 2.38 that no one
    // ever sees — which is exactly how this failed on CI while passing locally.
    $page = openCreateChannelDialog(
        signInThroughBrowser($alice),
        390,
        844,
        browserChannelUrl($team, $channel),
    )
        ->wait(0.5)
        ->assertNoAccessibilityIssues();

    $page->script(<<<'JS'
    () => {
        localStorage.setItem('appearance', 'dark');
        document.documentElement.classList.add('dark');
        document.documentElement.style.colorScheme = 'dark';
    }
    JS);

    $page->wait(0.5)->assertNoAccessibilityIssues();
});
