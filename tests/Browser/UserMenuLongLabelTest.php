<?php

declare(strict_types=1);

use App\Enums\AppLocale;
use App\Models\User;

/**
 * The user menu is only ever as wide as the dock it hangs off (its content
 * tracks the trigger width and floors at min-w-64), so a locale whose
 * translation runs longer than the English has nowhere to go. Every label row
 * therefore renders its text as a truncating flex child rather than as a bare
 * text node: without that, "Pause notifications" — twice the length in French —
 * wrapped out of its fixed h-9 row and left the submenu chevron floating beside
 * a two-line block (#760).
 */

/**
 * A script asserting every fixed-height label row of the presence menu still
 * measures exactly one 36px line (h-9) — the height a wrapped label overflows.
 */
function everyMenuRowIsOneLine(): string
{
    $rows = json_encode([
        'set-status-menu-item',
        'toggle-presence-menu-item',
        'pause-notifications-menu-item',
        'settings-menu-item',
        'keyboard-shortcuts-menu-item',
        'replay-tour-menu-item',
    ], JSON_THROW_ON_ERROR);

    return <<<JS
    (() => {
        const rows = {$rows}.map(name => document.querySelector('[data-test="' + name + '"]'));

        return rows.every(row => row !== null && Math.round(row.getBoundingClientRect().height) === 36);
    })()
    JS;
}

test('the presence menu keeps its rows on one line in French', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();
    $alice->update(['locale' => AppLocale::French]);

    signInThroughBrowser($alice)
        ->click('@sidebar-menu-button')
        ->assertPresent('@pause-notifications-menu-item')
        // Let the dropdown settle past its open/pointer-grace window.
        ->wait(0.5)
        // The French catalog is the one the middleware inlined...
        ->assertSee('Pause des notifications')
        ->assertSee('Définir un statut')
        ->assertSee('Se marquer absent')
        // ...and no row has grown a second line under it.
        ->assertScript(everyMenuRowIsOneLine(), true)
        // The shipped French label fits the row outright, so the guard below
        // never has to ellipsise it — no French user reads a clipped label.
        ->assertScript(<<<'JS'
        (() => {
            const label = document.querySelector('[data-test="pause-notifications-menu-item"] span');

            return label.scrollWidth <= label.clientWidth;
        })()
        JS, true)
        // The submenu chevron still sits on the row's own centre line, rather
        // than beside a taller block.
        ->assertScript(<<<'JS'
        (() => {
            const row = document.querySelector('[data-test="pause-notifications-menu-item"]');
            const chevron = [...row.querySelectorAll('svg')].at(-1);
            const rowBox = row.getBoundingClientRect();
            const chevronBox = chevron.getBoundingClientRect();

            return Math.abs(
                (chevronBox.top + chevronBox.height / 2) - (rowBox.top + rowBox.height / 2),
            ) <= 1;
        })()
        JS, true)
        // The pause presets and the quiet-hours footer row of the flyout hold
        // their own heights in French too.
        ->click('@pause-notifications-menu-item')
        ->assertPresent('@pause-notifications-submenu')
        ->assertScript(<<<'JS'
        (() => {
            const submenu = document.querySelector('[data-test="pause-notifications-submenu"]');
            const rows = [...submenu.querySelectorAll('[data-test]')];

            return rows.length > 0
                && rows.every(row => Math.round(row.getBoundingClientRect().height) === 32)
                && submenu.scrollWidth <= submenu.clientWidth;
        })()
        JS, true);
});

test('a menu label longer than the row ellipsises instead of wrapping', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    // The guard has to hold for any locale, not just the French string that
    // exposed it, so drive it with a label no translation would ever exceed.
    signInThroughBrowser($alice)
        ->click('@sidebar-menu-button')
        ->assertPresent('@pause-notifications-menu-item')
        ->wait(0.5)
        ->assertScript(<<<'JS'
        (() => {
            const label = document.querySelector('[data-test="pause-notifications-menu-item"] span');
            label.textContent = 'Benachrichtigungen vorübergehend stummschalten und pausieren';

            return true;
        })()
        JS, true)
        ->assertScript(everyMenuRowIsOneLine(), true)
        ->assertScript(<<<'JS'
        (() => {
            const label = document.querySelector('[data-test="pause-notifications-menu-item"] span');

            // One rendered line, clipped rather than wrapped: the text overflows
            // its own box (so the ellipsis shows) but the row does not.
            return label.getClientRects().length === 1
                && label.scrollWidth > label.clientWidth
                && getComputedStyle(label).textOverflow === 'ellipsis';
        })()
        JS, true);
});

test('the presence menu renders at its narrowest supported width', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();
    $alice->update(['locale' => AppLocale::French]);

    // The rows above are measured at the narrow end of the menu, so pin that:
    // the content tracks the trigger width but floors at min-w-64 (256px), and
    // the dock is exactly that wide. The mobile sheet is wider (18rem), and the
    // collapsed dock takes the trigger off-canvas with it, so there is no
    // narrower state to check.
    signInThroughBrowser($alice)
        // See #764 — the French catalog only reaches the client on a document load.
        ->refresh()
        ->click('@sidebar-menu-button')
        ->assertPresent('@pause-notifications-menu-item')
        ->wait(0.5)
        ->assertScript(<<<'JS'
        (() => {
            const menu = document.querySelector('[data-test="pause-notifications-menu-item"]')
                .closest('[data-slot="dropdown-menu-content"]');

            return Math.round(menu.getBoundingClientRect().width) === 256;
        })()
        JS, true);
});

/**
 * The paused card is the one row where two siblings compete for the width
 * rather than a label competing with the row height: its label column carries
 * the state readout, and a one-tap pill sits beside it. The pill used to be
 * unshrinkable, so the label — a flex child with a zero basis — was the only
 * thing that could give, and in French "Suspendre l'horaire aujourd'hui"
 * squeezed it to nothing while the card overflowed its own box (#762).
 */

/**
 * A script asserting the paused card still reads: the label column holds a real
 * width, neither of its two lines is clipped, and the card itself does not
 * overflow.
 */
function pausedCardStillReads(): string
{
    return <<<'JS'
    (() => {
        const card = document.querySelector('[data-test="dnd-paused-card"]');
        const label = document.querySelector('[data-test="dnd-paused-label"]');
        const lines = [...label.children];

        return card.scrollWidth <= card.clientWidth
            && label.getBoundingClientRect().width > 0
            && lines.length === 2
            && lines.every(line => line.scrollWidth <= line.clientWidth);
    })()
    JS;
}

/**
 * Put the viewer inside a quiet-hours window straddling this very minute, so
 * the paused card is showing whenever the suite happens to run — near midnight
 * the bounds wrap, which the evaluator reads as an overnight window.
 */
function insideQuietHours(User $user): void
{
    $now = now('UTC');

    $user->forceFill([
        'timezone' => 'UTC',
        'dnd_schedule_enabled' => true,
        'dnd_starts_at' => $now->subMinutes(10)->format('H:i'),
        'dnd_ends_at' => $now->addMinutes(10)->format('H:i'),
    ])->save();
}

test('the paused card keeps its quiet-hours readout legible in French', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();
    $alice->update(['locale' => AppLocale::French]);
    insideQuietHours($alice);

    signInThroughBrowser($alice)
        ->click('@sidebar-menu-button')
        ->assertPresent('@dnd-paused-card')
        ->wait(0.5)
        // The state the card exists to show is on screen, not squeezed out by
        // the pill standing next to it...
        ->assertSee('En pause')
        ->assertSee('heures calmes')
        ->assertSee("Suspendre l'horaire aujourd'hui")
        ->assertScript(pausedCardStillReads(), true);
});

test('the paused card holds up against a pill label no locale would exceed', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();
    insideQuietHours($alice);

    signInThroughBrowser($alice)
        ->click('@sidebar-menu-button')
        ->assertPresent('@dnd-snooze-menu-item')
        ->wait(0.5)
        // The English pill left the readout 14px to live in, which was already
        // too little to read even before the French one erased it outright.
        ->assertScript(pausedCardStillReads(), true)
        ->assertScript(<<<'JS'
        (() => {
            const pill = document.querySelector('[data-test="dnd-snooze-menu-item"] span');
            pill.textContent = 'Heutigen Zeitplan für ruhige Stunden vorübergehend aussetzen';

            return true;
        })()
        JS, true)
        ->assertScript(pausedCardStillReads(), true)
        // A pill wider than the card itself is clipped to an ellipsis rather
        // than allowed to push the card open.
        ->assertScript(<<<'JS'
        (() => {
            const pill = document.querySelector('[data-test="dnd-snooze-menu-item"] span');

            return pill.scrollWidth > pill.clientWidth
                && getComputedStyle(pill).textOverflow === 'ellipsis';
        })()
        JS, true);
});

test('the paused card leaves the manual-pause variant on one line', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();
    $alice->update(['locale' => AppLocale::French]);
    $alice->forceFill(['dnd_until' => now()->addMinutes(30)])->save();

    // "Reprendre" is short enough to sit beside the label, so the manual pause
    // keeps the single-row card it always had — the wrapping guard below only
    // bites when the pill genuinely cannot fit.
    signInThroughBrowser($alice)
        ->click('@sidebar-menu-button')
        ->assertPresent('@dnd-resume-menu-item')
        ->wait(0.5)
        ->assertSee('Reprendre')
        ->assertScript(pausedCardStillReads(), true)
        ->assertScript(<<<'JS'
        (() => {
            const label = document.querySelector('[data-test="dnd-paused-label"]');
            const pill = document.querySelector('[data-test="dnd-resume-menu-item"]');

            return pill.getBoundingClientRect().top < label.getBoundingClientRect().bottom;
        })()
        JS, true);
});
