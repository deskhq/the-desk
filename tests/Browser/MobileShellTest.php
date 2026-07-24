<?php

declare(strict_types=1);

use App\Actions\Channels\JoinChannel;
use App\Enums\AppLocale;
use App\Models\Channel;

/**
 * The mobile breakpoint's shell: one pane below `md`, and no width at which the
 * dock is unreachable.
 *
 * The boundary case is the one that regressed silently (#771). The dock's
 * "is mobile" test used to be `(max-width: 768px)`, which is true *at* 768,
 * while Tailwind's `md:` applies *from* 768 up. At exactly that width the dock
 * rendered as a Sheet — so no rail existed — while `md:hidden` hid the trigger
 * that opens the Sheet, leaving no route to the dock at all.
 */

/**
 * Whether the always-mounted desktop rail is the dock's current treatment. The
 * Sheet marks itself `data-mobile="true"`, so its absence distinguishes the two.
 */
function dockRailIsLive(): string
{
    return <<<'JS'
    (() => document.querySelector('[data-slot="sidebar"]') !== null
        && document.querySelector('[data-slot="sidebar"][data-mobile="true"]') === null)()
    JS;
}

/**
 * Whether the masthead's trigger — the only route to the dock once it is a
 * Sheet — is actually on screen.
 */
function dockTriggerIsVisible(): string
{
    return <<<'JS'
    (() => {
        const trigger = document.querySelector('[data-test="sidebar-toggle"]');

        return trigger !== null
            && trigger.getBoundingClientRect().width > 0
            && getComputedStyle(trigger).visibility !== 'hidden';
    })()
    JS;
}

test('exactly one dock treatment is live at every width across the breakpoint', function (int $width, bool $expectRail): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    // Whichever side of the breakpoint this width falls on, the dock is
    // reachable: the rail from `md` up, the Sheet trigger below it. Never both,
    // and — the regression this guards — never neither.
    signInThroughBrowser($alice)
        ->resize($width, 900)
        ->navigate(browserChannelUrl($team, $channel))
        ->assertScript(dockRailIsLive(), $expectRail)
        ->assertScript(dockTriggerIsVisible(), ! $expectRail);
})->with([
    'the tightest phone' => [360, false],
    'the last mobile width' => [767, false],
    'the breakpoint itself is desktop' => [768, true],
    'just above the breakpoint' => [769, true],
]);

test('the channel screen never scrolls horizontally at a phone width', function (int $width, int $height): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    signInThroughBrowser($alice)
        ->resize($width, $height)
        ->navigate(browserChannelUrl($team, $channel))
        ->assertScript(
            '(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)()',
            true,
        )
        // Nothing the tests can name is clipped off the right edge either — a
        // page can measure no wider than the viewport while still hiding a
        // control past it under `overflow: hidden`.
        ->assertScript(<<<'JS'
        (() => [...document.querySelectorAll('[data-test]')]
            .every(el => el.getBoundingClientRect().right <= window.innerWidth + 1))()
        JS, true);
})->with([
    'small phone' => [360, 740],
    'iPhone SE' => [375, 667],
    'iPhone 14' => [390, 844],
    'large phone' => [430, 932],
    'landscape phone' => [740, 360],
]);

test('the channel name is readable however tight the viewport', function (int $width): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    // The masthead's right-hand controls used to be unshrinkable, which left the
    // <h1> measuring zero: the channel you were in had no name on a phone.
    signInThroughBrowser($alice)
        ->resize($width, 740)
        ->navigate(browserChannelUrl($team, $channel))
        ->assertScript(<<<'JS'
        (() => {
            const heading = document.querySelector('main h1, header h1');

            return heading !== null && heading.getBoundingClientRect().width > 80;
        })()
        JS, true);
})->with([
    'small phone' => [360],
    'iPhone SE' => [375],
    'iPhone 14' => [390],
    // Tablet portrait is past the breakpoint, so the dock takes its 300px back
    // and leaves the masthead the room of a large phone. What it can fit is a
    // question about the pane, not the window, which is why the masthead sizes
    // itself off its own container rather than the viewport.
    'tablet portrait' => [768],
]);

test('a long channel name truncates rather than pushing the search icon off screen', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    $channel->update(['name' => 'quarterly-planning-and-roadmap-review-working-group']);

    signInThroughBrowser($alice)
        ->resize(360, 740)
        ->navigate(browserChannelUrl($team, $channel))
        // The search affordance stays wholly within the viewport...
        ->assertScript(<<<'JS'
        (() => {
            const search = document.querySelector('[data-test="masthead-search"]');
            const box = search.getBoundingClientRect();

            return box.right <= window.innerWidth && box.left >= 0;
        })()
        JS, true)
        // ...because the name gave way instead, ellipsised on one line.
        ->assertScript(<<<'JS'
        (() => {
            const name = document.querySelector('header h1 span:last-child');

            return name.scrollWidth > name.clientWidth;
        })()
        JS, true);
});

test('the dock opens full screen and its every row clears a 44px touch target', function (int $width, int $height): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    // A 300px strip left a sliver of dimmed channel behind it that served no
    // purpose on a phone (#834): the open dock now owns the whole viewport,
    // edge to edge.
    signInThroughBrowser($alice)
        ->resize($width, $height)
        ->navigate(browserChannelUrl($team, $channel))
        ->click('@sidebar-toggle')
        ->assertPresent('@channels-nav')
        ->assertScript(<<<'JS'
        (() => {
            const box = document.querySelector('[data-slot="sidebar"][data-mobile="true"]')
                .getBoundingClientRect();

            return Math.round(box.width) === window.innerWidth
                && Math.round(box.height) === window.innerHeight;
        })()
        JS, true)
        // Channel and direct-message rows are the ones a thumb hits most, and
        // they carry the full 44px target.
        ->assertScript(<<<'JS'
        (() => [...document.querySelectorAll(
            '[data-test="section-content-channels"] li a, [data-test="section-content-direct"] li a'
        )].every(row => Math.round(row.getBoundingClientRect().height) >= 44))()
        JS, true)
        // The utility rows and the jump-to field clear the design's 38px floor.
        ->assertScript(<<<'JS'
        (() => [
            '[data-test="threads-inbox"]',
            '[data-test="reminders-trigger"]',
            '[data-test="search-messages"]',
            '[data-test="browse-channels"]',
            '[data-test="quick-switcher-trigger"]',
        ].every(selector => {
            const row = document.querySelector(selector);

            return row !== null && Math.round(row.getBoundingClientRect().height) >= 38;
        }))()
        JS, true);
})->with([
    'small phone' => [360, 740],
    'iPhone 14' => [390, 844],
    // Landscape trips the sheet primitive's `sm:max-w-sm` cap, which only a
    // full-screen dock needs lifted.
    'landscape phone' => [740, 360],
]);

test('the dock header carries its own close affordance below the breakpoint', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    // Full screen leaves no visible scrim to tap (#834), so the header takes
    // over as the visible way out. The desktop rail is no dialog and offers no
    // such button.
    signInThroughBrowser($alice)
        ->resize(390, 844)
        ->navigate(browserChannelUrl($team, $channel))
        ->click('@sidebar-toggle')
        ->assertVisible('@dock-close')
        ->click('@dock-close')
        ->assertNotPresent('@channels-nav')
        ->resize(1280, 800)
        ->assertPresent('@channels-nav')
        ->assertNotPresent('@dock-close');
});

test('the dock dismisses without a reload', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    signInThroughBrowser($alice)
        ->resize(390, 844)
        ->navigate(browserChannelUrl($team, $channel))
        ->click('@sidebar-toggle')
        ->assertPresent('@channels-nav')
        // Dismissed from the keyboard rather than the scrim: the scrim spans the
        // whole viewport with the sheet on top of it, so a click driven at its
        // centre lands on the sheet. Both routes run through the same
        // `setOpenMobile(false)`, and the swipe-to-dismiss gesture is covered by
        // its own unit tests over `swipeIntent`.
        ->keys('@channels-nav', ['Escape'])
        ->assertNotPresent('@channels-nav');
});

test('tapping a channel in the dock navigates and closes it in the same gesture', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $general] = browserTeamWithChannel();

    $planning = Channel::factory()->for($team)->create([
        'name' => 'planning',
        'slug' => 'planning',
    ]);
    app(JoinChannel::class)->handle($planning, $alice);

    // Navigation used to leave the sheet open on top of the destination, a tap
    // tax on the most common action in the app (#834): the visit and the
    // dismissal are now the same gesture.
    signInThroughBrowser($alice)
        ->resize(390, 844)
        ->navigate(browserChannelUrl($team, $general))
        ->click('@sidebar-toggle')
        ->assertPresent('@channels-nav')
        ->click('[data-test="section-content-channels"] a[href$="/planning"]')
        ->assertPathIs(browserChannelUrl($team, $planning))
        ->assertNotPresent('@channels-nav');
});

test('closing on navigation hands focus to the destination pane', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $general] = browserTeamWithChannel();

    $planning = Channel::factory()->for($team)->create([
        'name' => 'planning',
        'slug' => 'planning',
    ]);
    app(JoinChannel::class)->handle($planning, $alice);

    // The sheet's focus trap must release cleanly: the row it would restore
    // focus to unmounts with the sheet, and letting focus drop to <body> strands
    // keyboard users. It lands on the destination pane instead — the same
    // #main the skip link targets.
    signInThroughBrowser($alice)
        ->resize(390, 844)
        ->navigate(browserChannelUrl($team, $general))
        ->click('@sidebar-toggle')
        ->assertPresent('@channels-nav')
        ->click('[data-test="section-content-channels"] a[href$="/planning"]')
        ->assertNotPresent('@channels-nav')
        ->assertScript(<<<'JS'
        (() => document.activeElement === document.getElementById('main'))()
        JS, true);
});

test('tapping the channel the user is already in still closes the dock', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $general] = browserTeamWithChannel();

    // A same-URL visit never fires Inertia's `navigate` event (it replaces the
    // history entry instead of pushing one), but the row is still a navigating
    // gesture to the user: the dock has to get out of the way all the same.
    signInThroughBrowser($alice)
        ->resize(390, 844)
        ->navigate(browserChannelUrl($team, $general))
        ->click('@sidebar-toggle')
        ->assertPresent('@channels-nav')
        ->click("[data-test=\"section-content-channels\"] a[href$=\"/{$general->slug}\"]")
        ->assertPathIs(browserChannelUrl($team, $general))
        ->assertNotPresent('@channels-nav');
});

test('collapsing a section keeps the dock open', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    // The collapse persists through an Inertia partial reload, which must not
    // read as a navigation: the user is arranging the dock, not leaving it.
    signInThroughBrowser($alice)
        ->resize(390, 844)
        ->navigate(browserChannelUrl($team, $channel))
        ->click('@sidebar-toggle')
        ->assertVisible('@section-content-channels')
        // Flag the persisting PATCH's completion so the final assertions run on
        // the far side of the round trip — a wrongful close would fire there,
        // after the optimistic assertions had already passed.
        ->assertScript(<<<'JS'
        (() => {
            window.__collapsePersisted = false;
            document.addEventListener('inertia:finish', () => {
                window.__collapsePersisted = true;
            }, { once: true });

            return true;
        })()
        JS, true)
        ->click('@section-toggle-channels')
        ->assertMissing('@section-content-channels')
        ->assertScript('window.__collapsePersisted', true)
        ->assertPresent('@channels-nav')
        ->assertScript(<<<'JS'
        (() => document.querySelector('[data-slot="sidebar"][data-mobile="true"]') !== null)()
        JS, true);
});

test('the composer stays a pill that keeps its field and Send button usable', function (int $width, int $height): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    // The tool cluster used to squeeze the field to zero width, which wrapped
    // its placeholder one character per line and inflated the composer to its
    // 200px cap — most of a landscape phone's screen.
    signInThroughBrowser($alice)
        ->resize($width, $height)
        ->navigate(browserChannelUrl($team, $channel))
        // The field keeps a usable share of the pill, and — the actual tell of
        // the collapse — stays a single line rather than growing to its 200px
        // cap as a zero-width field's wrapped placeholder used to make it.
        ->assertScript(<<<'JS'
        (() => {
            const field = document.querySelector('[data-test="message-composer-input"]').getBoundingClientRect();

            return field.width >= 100 && field.height <= 60;
        })()
        JS, true)
        // The whole composer stays a single pill rather than a block.
        ->assertScript(<<<'JS'
        (() => document.querySelector('[data-tour="composer"]')
            .getBoundingClientRect().height < 100)()
        JS, true)
        // ...and the timeline, not the chrome, gets the majority of the screen.
        ->assertScript(<<<'JS'
        (() => {
            const chrome = document.querySelector('header').getBoundingClientRect().height
                + document.querySelector('[data-tour="composer"]').getBoundingClientRect().height;

            return chrome < window.innerHeight / 2;
        })()
        JS, true);
})->with([
    'small phone' => [360, 740],
    'iPhone SE' => [375, 667],
    'iPhone 14' => [390, 844],
    'landscape phone' => [740, 360],
    // Same reasoning as the masthead: the pill folds its tools away whenever the
    // pane is narrow, which a tablet's is even though its window is not.
    'tablet portrait' => [768, 1024],
]);

test('the composer pill survives a locale with longer words than the English', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();
    $alice->update(['locale' => AppLocale::French]);

    // French runs longer than English almost everywhere ("Envoyer" against
    // "Send"), and a tight row is where that first shows (#765). The pill has to
    // absorb it by dropping the label, not by narrowing the field until its
    // placeholder wraps onto a second line.
    signInThroughBrowser($alice)
        ->resize(360, 740)
        // The catalog rides the initial response as a `once` prop, so signing in
        // from the guest login page leaves the client on the English one until a
        // document load re-inlines it (#764).
        ->navigate(browserChannelUrl($team, $channel))
        ->assertScript(<<<'JS'
        (() => {
            const field = document.querySelector('[data-test="message-composer-input"]');

            // One line: a wrapped placeholder measures two line-heights.
            return Math.round(field.getBoundingClientRect().height) <= 32;
        })()
        JS, true)
        ->assertScript(<<<'JS'
        (() => document.querySelector('[data-test="message-composer-input"]')
            .getBoundingClientRect().width >= 150)()
        JS, true);
});

test('every compose tool is still reachable once disclosed', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    // Folding the tools away must not remove them: each one is still there,
    // one tap further in.
    signInThroughBrowser($alice)
        ->resize(390, 844)
        ->navigate(browserChannelUrl($team, $channel))
        ->assertScript(<<<'JS'
        (() => document.querySelector('[data-test="composer-tools"]')
            .getBoundingClientRect().height === 0)()
        JS, true)
        ->click('@composer-tools-toggle')
        ->assertVisible('@message-composer-attach')
        ->assertVisible('@composer-format-cluster')
        // Disclosing them widens the pill onto a second row rather than
        // narrowing the field.
        // The field keeps a usable share of the pill, and — the actual tell of
        // the collapse — stays a single line rather than growing to its 200px
        // cap as a zero-width field's wrapped placeholder used to make it.
        ->assertScript(<<<'JS'
        (() => {
            const field = document.querySelector('[data-test="message-composer-input"]').getBoundingClientRect();

            return field.width >= 100 && field.height <= 60;
        })()
        JS, true);
});
