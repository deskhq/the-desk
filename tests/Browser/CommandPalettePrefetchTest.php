<?php

declare(strict_types=1);

use App\Actions\Channels\JoinChannel;
use App\Models\Channel;
use App\Models\Team;

/**
 * What the shell fetches before the viewer has asked for anything (#1257).
 *
 * Two navigation paths have no hover to trigger on, so both predict instead:
 * the palette fetches the row `Enter` would run once the arrowing settles, and
 * the shell fetches the two channels a ⌘↑ / ⌘↓ walk can reach. Which URLs those
 * are, which cache tags they carry and how many requests a burst costs are
 * pinned in Vitest, where they are deterministic. What only a browser shows is
 * the halves meeting: real speculative requests leaving the page, and the pick
 * that follows still arriving at the right channel with a prediction live in
 * front of it.
 *
 * Deliberately no timing assertion. The requests are polled for rather than
 * slept on, and nothing here claims a navigation was fast.
 *
 * The roster sorts by name (see `SidebarChannels`), so with the channels below
 * it reads alpha, beta, general, zulu. Sitting in #general puts the walk's two
 * neighbours at beta and zulu and leaves **alpha** reachable only through the
 * palette — which is what lets one test speak for one path.
 */

/** The channels this suite's workspace holds, in the order the sidebar lists them. */
function paletteWorkspace(): array
{
    ['owner' => $alice, 'team' => $team, 'channel' => $general] = browserTeamWithChannel();

    foreach (['alpha', 'beta', 'zulu'] as $name) {
        $channel = Channel::factory()->for($team)->create([
            'name' => $name,
            'slug' => $name,
        ]);
        app(JoinChannel::class)->handle($channel, $alice);
    }

    return ['owner' => $alice, 'team' => $team, 'active' => $general];
}

/**
 * A script resolving once the browser has fetched `$path`.
 *
 * Read off `PerformanceResourceTiming` rather than a network panel: the app
 * registers a no-op service worker, which makes every request appear twice to
 * Playwright's own reporting. Existence is all this asks, so a duplicate is
 * harmless — but the entry lands a debounce after the row is highlighted, and
 * `assertScript` never retries (#947), so the frames are polled out here.
 */
function speculativeFetchArrives(string $path): string
{
    return <<<JS
    (async () => {
        const fetched = () => performance
            .getEntriesByType('resource')
            .some((entry) => entry.name.includes('{$path}'));

        for (let frame = 0; frame < 240 && ! fetched(); frame++) {
            await new Promise(requestAnimationFrame);
        }

        return fetched();
    })()
    JS;
}

/** Whether `$path` has been fetched at all, asked once and now. */
function hasBeenFetched(string $path): string
{
    return <<<JS
    (() => performance
        .getEntriesByType('resource')
        .some((entry) => entry.name.includes('{$path}')))()
    JS;
}

test('the highlighted row is fetched before it is picked, and picking it lands there', function (): void {
    ['owner' => $alice, 'team' => $team, 'active' => $general] = paletteWorkspace();

    signInThroughBrowser($alice)
        ->resize(1280, 900)
        ->navigate(browserChannelUrl($team, $general))
        // Nothing has reason to want #alpha yet: it is neither open nor a
        // neighbour of the open channel. Asserting that here is what stops the
        // walk's own prediction from standing in for the palette's below — and
        // fails loudly if the roster ever stops sorting the way this assumes.
        ->assertScript(hasBeenFetched('/c/alpha'), false)
        ->click('@quick-switcher-trigger')
        // Narrowing to one row names which channel the prediction is for
        // without simulating a single arrow key: the list highlights its first.
        ->type('@quick-switcher-input', 'alpha')
        ->assertScript(speculativeFetchArrives('/c/alpha'), true)
        ->click('@quick-switcher-channel')
        ->assertPathContains('/c/alpha');
});

test('the channels either side of the open one are fetched without being asked for', function (): void {
    ['owner' => $alice, 'team' => $team, 'active' => $general] = paletteWorkspace();

    signInThroughBrowser($alice)
        ->resize(1280, 900)
        ->navigate(browserChannelUrl($team, $general))
        ->assertScript(speculativeFetchArrives('/c/beta'), true)
        ->assertScript(speculativeFetchArrives('/c/zulu'), true)
        // The walk reaches two channels, so the prediction stops at two: #alpha
        // is in the same workspace and stays unfetched.
        ->assertScript(hasBeenFetched('/c/alpha'), false);
});

test('a channel the walk cannot reach in one step is left alone in a large workspace', function (): void {
    ['owner' => $alice, 'team' => $team] = paletteWorkspace();

    $far = Channel::factory()->for($team)->create(['name' => 'middle', 'slug' => 'middle']);
    app(JoinChannel::class)->handle($far, $alice);

    $team = Team::findOrFail($team->id);

    signInThroughBrowser($alice)
        ->resize(1280, 900)
        ->navigate(browserChannelUrl($team, Channel::where('slug', 'alpha')->firstOrFail()))
        // Sitting at the top of the list, the walk wraps: beta below, zulu
        // above. #general and #middle sit between them and are never fetched,
        // which is the cost not growing with the workspace.
        ->assertScript(speculativeFetchArrives('/c/beta'), true)
        ->assertScript(hasBeenFetched('/c/middle'), false)
        ->assertScript(hasBeenFetched('/c/general'), false);
});
