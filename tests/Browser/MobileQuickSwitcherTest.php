<?php

declare(strict_types=1);

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
