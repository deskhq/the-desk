<?php

declare(strict_types=1);

use App\Actions\Channels\JoinChannel;
use App\Models\Channel;
use App\Models\Message;

/**
 * The mobile quick switcher (#775): below `md` the ⌘K palette becomes a
 * full-screen overlay entered from the masthead search icon, listing recent
 * channels before anything is typed (screen `m5` of the mobile design).
 */
test('the masthead search icon opens the switcher as a full-screen overlay below md', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    signInThroughBrowser($alice)
        ->resize(390, 844)
        ->navigate(browserChannelUrl($team, $channel))
        ->click('@masthead-search')
        // The tap opens the overlay in place — it must not navigate to the
        // search page the way the desktop icon does.
        ->assertPathContains("/c/{$channel->slug}")
        ->assertVisible('@quick-switcher-input')
        // The overlay is the screen: its panel spans the whole viewport.
        ->assertScript(<<<'JS'
        (() => {
            const panel = document.querySelector('[data-slot="dialog-content"]');
            const box = panel.getBoundingClientRect();

            return Math.round(box.width) >= window.innerWidth
                && Math.round(box.height) >= window.innerHeight - 1
                && Math.round(box.top) === 0;
        })()
        JS, true);
});

test('Cancel and Escape both dismiss the overlay', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    signInThroughBrowser($alice)
        ->resize(390, 844)
        ->navigate(browserChannelUrl($team, $channel))
        ->click('@masthead-search')
        ->assertVisible('@quick-switcher-cancel')
        ->click('@quick-switcher-cancel')
        ->assertNotPresent('@quick-switcher-input')
        ->click('@masthead-search')
        ->assertVisible('@quick-switcher-input')
        ->keys('@quick-switcher-input', ['Escape'])
        ->assertNotPresent('@quick-switcher-input');
});

test('an empty query lists channels most recently active first', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $general] = browserTeamWithChannel();

    $planning = Channel::factory()->for($team)->create([
        'name' => 'planning',
        'slug' => 'planning',
    ]);
    $design = Channel::factory()->for($team)->create([
        'name' => 'design',
        'slug' => 'design',
    ]);
    app(JoinChannel::class)->handle($planning, $alice);
    app(JoinChannel::class)->handle($design, $alice);

    // Joining just posted "member joined" notices stamped now in every channel,
    // which would drown the activity times this test is about — push them all
    // into last week so the deliberate messages below are each channel's latest.
    Message::query()->update(['created_at' => now()->subWeek()]);

    // Activity order: design spoke just now, #general yesterday, planning two
    // days ago.
    Message::factory()->for($planning)->for($alice, 'user')->create(['created_at' => now()->subDays(2)]);
    Message::factory()->for($general)->for($alice, 'user')->create(['created_at' => now()->subDay()]);
    Message::factory()->for($design)->for($alice, 'user')->create(['created_at' => now()]);

    signInThroughBrowser($alice)
        ->resize(390, 844)
        ->navigate(browserChannelUrl($team, $general))
        ->click('@masthead-search')
        ->assertScript(<<<'JS'
        (() => {
            const names = [...document.querySelectorAll('[data-test="quick-switcher-channel"]')]
                .map(row => row.textContent);

            return names.length === 3
                && names[0].includes('design')
                && names[1].includes('general')
                && names[2].includes('planning');
        })()
        JS, true);
});

test('typing filters into the channel and people groups with the match highlighted', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $general] = browserTeamWithChannel();

    // "bo" reaches both groups: the boardroom channel and Bob Member.
    $boardroom = Channel::factory()->for($team)->create([
        'name' => 'boardroom',
        'slug' => 'boardroom',
    ]);
    app(JoinChannel::class)->handle($boardroom, $alice);

    signInThroughBrowser($alice)
        ->resize(390, 844)
        ->navigate(browserChannelUrl($team, $general))
        ->click('@masthead-search')
        ->type('@quick-switcher-input', 'bo')
        ->assertScript(<<<'JS'
        (() => {
            const channels = [...document.querySelectorAll('[data-test="quick-switcher-channel"]')];
            const people = [...document.querySelectorAll('[data-test="quick-switcher-person"]')];

            return channels.length === 1
                && channels[0].textContent.includes('boardroom')
                && people.length === 1
                && people[0].textContent.includes('Bob Member');
        })()
        JS, true)
        // Each surviving row brightens the run of its name the query matched.
        ->assertScript(<<<'JS'
        (() => [...document.querySelectorAll('[data-test="quick-switcher-channel"], [data-test="quick-switcher-person"]')]
            .every(row => row.querySelector('[data-test="quick-switcher-match"]')?.textContent.toLowerCase() === 'bo'))()
        JS, true)
        // Every row carries the design's 46px height — comfortably past the
        // 44px touch floor.
        ->assertScript(<<<'JS'
        (() => [...document.querySelectorAll('[data-test="quick-switcher-channel"], [data-test="quick-switcher-person"]')]
            .every(row => Math.round(row.getBoundingClientRect().height) >= 44))()
        JS, true);
});

test('selecting a channel row navigates to it, as it does today', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $general] = browserTeamWithChannel();

    $boardroom = Channel::factory()->for($team)->create([
        'name' => 'boardroom',
        'slug' => 'boardroom',
    ]);
    app(JoinChannel::class)->handle($boardroom, $alice);

    signInThroughBrowser($alice)
        ->resize(390, 844)
        ->navigate(browserChannelUrl($team, $general))
        ->click('@masthead-search')
        ->type('@quick-switcher-input', 'boardroom')
        ->click('@quick-switcher-channel')
        ->assertPathContains('/c/boardroom');
});

test('the overlay has no serious accessibility violations, light or dark', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    // The settle lets the overlay's 200ms fade finish: axe blends the
    // mid-animation opacity into its contrast arithmetic otherwise.
    $page = signInThroughBrowser($alice)
        ->resize(390, 844)
        ->navigate(browserChannelUrl($team, $channel))
        ->click('@masthead-search')
        ->assertVisible('@quick-switcher-input')
        ->wait(0.5)
        ->assertNoAccessibilityIssues();

    // Re-audit against the dark palette; persisting to localStorage first keeps
    // the appearance controller from re-resolving 'system' back to light.
    $page->script(<<<'JS'
    () => {
        localStorage.setItem('appearance', 'dark');
        document.documentElement.classList.add('dark');
        document.documentElement.style.colorScheme = 'dark';
    }
    JS);

    $page->wait(0.5)
        ->assertNoAccessibilityIssues();
});

test('from md up the search icon still links to the search page and the palette stays a centred dialog', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    signInThroughBrowser($alice)
        ->resize(1280, 800)
        ->navigate(browserChannelUrl($team, $channel))
        // The sidebar's Jump-to entry opens the palette exactly as before: a
        // centred dialog, no mobile Cancel affordance.
        ->click('@quick-switcher-trigger')
        ->assertVisible('@quick-switcher-input')
        ->assertNotPresent('@quick-switcher-cancel')
        ->assertScript(<<<'JS'
        (() => {
            const panel = document.querySelector('[data-slot="dialog-content"]').getBoundingClientRect();

            return panel.width < window.innerWidth && panel.top > 0;
        })()
        JS, true)
        ->keys('@quick-switcher-input', ['Escape'])
        ->assertNotPresent('@quick-switcher-input')
        // The masthead icon keeps its desktop meaning: a link to the search page.
        ->click('@masthead-search')
        ->assertPathContains('/search');
});
