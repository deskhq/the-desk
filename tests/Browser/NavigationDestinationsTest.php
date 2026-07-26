<?php

declare(strict_types=1);

use App\Actions\Channels\JoinChannel;
use App\Enums\SidebarPosition;
use App\Models\Channel;

/**
 * The dock's pinned destinations: the desktop rail, the mobile tab bar, and the
 * `?nav=` bookkeeping that keeps the open one shareable and reload-proof.
 *
 * The panel swaps while the rail and the tab bar stay put, so what these tests
 * guard is that the surfaces survive the swap — the dock never jumps width, the
 * rail never lands between the panel and the workspace card, and the tab bar
 * never escapes the drawer into the conversation view (#834, #889, #890).
 */

/** Whether a destination's panel is the one the dock is currently rendering. */
function destinationPanelIsOpen(string $destination): string
{
    return <<<JS
    (() => document.querySelector('[data-test="destination-panel-{$destination}"]') !== null)()
    JS;
}

/**
 * A script resolving once the URL's `nav` param reaches the given destination —
 * `null` for the param being gone.
 *
 * Switching is a client-side visit ({@see router.replace}), so the panel swaps
 * synchronously off the ref while Inertia writes history a tick later: the URL
 * always lags the DOM here, and the URL assertions are one-shot reads. Polling
 * one animation frame at a time settles that without pinning a sleep to whatever
 * a loaded CI runner happens to take (#947).
 */
function navParamSettles(?string $destination): string
{
    $expected = $destination === null ? 'null' : "'{$destination}'";

    return <<<JS
    (async () => {
        const settled = () => new URLSearchParams(location.search).get('nav') === {$expected};

        for (let frame = 0; frame < 120 && ! settled(); frame++) {
            await new Promise(requestAnimationFrame);
        }

        return settled();
    })()
    JS;
}

/** The rendered width of the dock's card, rounded to the nearest pixel. */
function dockCardWidth(): string
{
    return <<<'JS'
    (() => Math.round(
        document.querySelector('[data-sidebar="sidebar"]').getBoundingClientRect().width,
    ))()
    JS;
}

test('a rail glyph swaps the panel and pins the destination on the URL', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    signInThroughBrowser($alice)
        ->resize(1280, 900)
        ->navigate(browserChannelUrl($team, $channel))
        ->assertPresent('@channels-nav')
        ->click('@rail-destination-threads')
        ->assertScript(destinationPanelIsOpen('threads'), true)
        // The conversation list gave up the panel, but the rail did not move and
        // the channel behind it stayed open.
        ->assertNotPresent('@channels-nav')
        ->assertPresent('@navigation-rail')
        ->assertScript(navParamSettles('threads'), true)
        ->assertQueryStringHas('nav', 'threads')
        ->assertPathIs(browserChannelUrl($team, $channel))
        // Back to the conversations, and the param goes with it.
        ->click('@rail-destination-channels')
        ->assertPresent('@channels-nav')
        ->assertScript(navParamSettles(null), true)
        ->assertQueryStringMissing('nav');
});

test('the dock keeps one width as the panel swaps between destinations', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    $page = signInThroughBrowser($alice)
        ->resize(1280, 900)
        ->navigate(browserChannelUrl($team, $channel))
        ->assertPresent('@channels-nav');

    // 356px is the drawn width: a 56px rail plus the 300px panel.
    $page->assertScript(dockCardWidth(), 356)
        ->click('@rail-destination-search')
        ->assertScript(destinationPanelIsOpen('search'), true)
        ->assertScript(dockCardWidth(), 356);
});

test('a ?nav= deep link restores its panel on a cold load', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    signInThroughBrowser($alice)
        ->resize(1280, 900)
        ->navigate(browserChannelUrl($team, $channel).'?nav=reminders')
        ->assertScript(destinationPanelIsOpen('reminders'), true)
        ->assertScript(
            '(() => document.querySelector(\'[data-test="rail-destination-reminders"]\').getAttribute("aria-current"))()',
            'true',
        );
});

test('the rail mirrors to the outer edge when the dock is docked right', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();
    $alice->update(['sidebar_position' => SidebarPosition::Right]);

    // Docked right the rail has to sit *outside* the panel, otherwise it lands
    // wedged between the panel and the workspace card it is meant to flank.
    signInThroughBrowser($alice)
        ->resize(1280, 900)
        ->navigate(browserChannelUrl($team, $channel))
        ->assertScript(<<<'JS'
        (() => {
            const rail = document.querySelector('[data-test="navigation-rail"]').getBoundingClientRect();
            const panel = document.querySelector('[data-test="channels-nav"]').getBoundingClientRect();

            return rail.left >= panel.right;
        })()
        JS, true);
});

test('the tab bar opens a destination inside the drawer and never leaves it', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    signInThroughBrowser($alice)
        ->resize(390, 844)
        ->navigate(browserChannelUrl($team, $channel))
        // The conversation view belongs to the timeline and the composer: no
        // tab bar competes with the thumb there.
        ->assertNotPresent('@navigation-tab-bar')
        ->click('@sidebar-toggle')
        ->assertPresent('@navigation-tab-bar')
        ->click('@tab-destination-search')
        ->assertScript(destinationPanelIsOpen('search'), true)
        // The bar stays put under the swapped panel, and the rail is desktop-only.
        ->assertPresent('@navigation-tab-bar')
        ->assertScript(
            '(() => document.querySelector(\'[data-test="navigation-rail"]\')?.checkVisibility() ?? false)()',
            false,
        );
});

test('opening a channel closes the drawer and takes the tab bar with it', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $general] = browserTeamWithChannel();

    $planning = Channel::factory()->for($team)->create([
        'name' => 'planning',
        'slug' => 'planning',
    ]);
    app(JoinChannel::class)->handle($planning, $alice);

    // #834 is unchanged by the tab bar: landing somewhere new is the dock's job
    // done, and the bar goes with the drawer rather than lingering over the
    // composer it would otherwise crowd.
    signInThroughBrowser($alice)
        ->resize(390, 844)
        ->navigate(browserChannelUrl($team, $general))
        ->click('@sidebar-toggle')
        ->assertPresent('@navigation-tab-bar')
        ->click('[data-test="section-content-channels"] a[href$="/planning"]')
        ->assertPathIs(browserChannelUrl($team, $planning))
        ->assertNotPresent('@navigation-tab-bar')
        ->assertNotPresent('@channels-nav');
});
