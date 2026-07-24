<?php

declare(strict_types=1);

use App\Actions\Channels\OpenDirectMessage;
use App\Enums\AppLocale;
use App\Models\Team;
use App\Models\User;

/**
 * The DM masthead's "Add people" control below the breakpoint (#801).
 *
 * At a phone width the full icon + label pill crowded the header until the
 * conversation name truncated to almost nothing. Below `md` the button folds
 * to an icon-only control like the neighbouring pins/search buttons — keeping
 * its accessible name and touch target — while the pill stays unchanged from
 * `md` up.
 */

/**
 * A team whose owner has a 1:1 DM open with a long-named counterpart — the
 * masthead title that exposed the crowding.
 *
 * @return array{owner: User, team: Team, url: string}
 */
function browserDmWithLongTitle(): array
{
    ['owner' => $alice, 'member' => $bob, 'team' => $team] = browserTeamWithChannel();

    $bob->update(['name' => 'Bartholomew Montgomery Featherstone']);

    $dm = app(OpenDirectMessage::class)->handle($team, $alice, $bob);

    return ['owner' => $alice, 'team' => $team, 'url' => browserChannelUrl($team, $dm)];
}

test('the Add people button is icon-only with its accessible name and touch target at phone widths', function (int $width, int $height): void {
    ['owner' => $alice, 'url' => $url] = browserDmWithLongTitle();

    signInThroughBrowser($alice)
        ->resize($width, $height)
        ->navigate($url)
        ->assertVisible('@masthead-add-people')
        // Icon-only: the accessible name moves onto the control itself...
        ->assertAttribute('@masthead-add-people', 'aria-label', 'Add people')
        // ...and no text label is rendered inside it.
        ->assertScript(<<<'JS'
        (() => document.querySelector('[data-test="masthead-add-people"]')
            .textContent.trim() === '')()
        JS, true)
        // The control keeps the masthead icon buttons' 36px (size-9) target.
        ->assertScript(<<<'JS'
        (() => {
            const box = document.querySelector('[data-test="masthead-add-people"]')
                .getBoundingClientRect();

            return Math.round(box.width) >= 36 && Math.round(box.height) >= 36;
        })()
        JS, true);
})->with([
    'small phone' => [360, 740],
    'iPhone SE' => [375, 667],
    'iPhone 14' => [390, 844],
    'large phone' => [430, 932],
]);

test('the long DM title keeps its horizontal space beside the icon-only control', function (): void {
    ['owner' => $alice, 'url' => $url] = browserDmWithLongTitle();

    // The defect: the pill plus the other controls squeezed the <h1> to ~40px,
    // so the name read "Bob …". Icon-only, the title keeps a readable share
    // (measured 114px at this width).
    signInThroughBrowser($alice)
        ->resize(360, 740)
        ->navigate($url)
        ->assertScript(<<<'JS'
        (() => document.querySelector('header h1')
            .getBoundingClientRect().width > 100)()
        JS, true);
});

test('the pill keeps its icon and label from the breakpoint up', function (): void {
    ['owner' => $alice, 'url' => $url] = browserDmWithLongTitle();

    signInThroughBrowser($alice)
        ->resize(1024, 800)
        ->navigate($url)
        ->assertSeeIn('@masthead-add-people', 'Add people');
});

test('the icon-only control still opens the add-people picker', function (): void {
    ['owner' => $alice, 'url' => $url] = browserDmWithLongTitle();

    signInThroughBrowser($alice)
        ->resize(390, 844)
        ->navigate($url)
        ->click('@masthead-add-people')
        ->assertVisible('@add-people-input');
});

test('the accessible name follows the locale at the tightest viewport', function (): void {
    ['owner' => $alice, 'url' => $url] = browserDmWithLongTitle();
    $alice->update(['locale' => AppLocale::French]);

    // French runs longer than English (#765); icon-only, the length lands in
    // the aria-label rather than the row, so the existing key just has to
    // reach the control.
    signInThroughBrowser($alice)
        ->resize(360, 740)
        ->navigate($url)
        ->assertAttribute('@masthead-add-people', 'aria-label', 'Ajouter des personnes');
});
