<?php

declare(strict_types=1);

use App\Models\Message;
use App\Models\User;

/**
 * Seed two of Bob's messages in #general that match "quokka", so the redesigned
 * search page renders highlighted snippets under a date group when Alice searches.
 *
 * @return array{owner: User}
 */
function searchPageWithMatches(): array
{
    ['owner' => $alice, 'member' => $bob, 'channel' => $channel] = browserTeamWithChannel();

    Message::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $bob->id,
        'body' => 'the quokka danced at dawn today',
    ]);
    Message::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $bob->id,
        'body' => 'another quokka sighting near the lake',
    ]);

    return ['owner' => $alice];
}

test('the search page highlights matches, groups them by date, and has no serious a11y violations in either theme', function (): void {
    ['owner' => $alice] = searchPageWithMatches();

    // Reach the page through the masthead search link — a client-side Inertia
    // visit, so the browser session survives (a full navigate() would drop it).
    $page = signInThroughBrowser($alice)
        ->click('@masthead-search')
        ->assertPathContains('/search')
        ->assertSee('Search your channels for messages.')
        ->type('@search-input', 'quokka')
        // Debounced scoped reload; wait it out, then the results render.
        ->wait(0.8)
        ->assertPresent('[data-test="search-result"]')
        // The snippet arrives as sanitized HTML with a brass <mark> highlight.
        ->assertPresent('[data-test="search-result"] mark')
        // Recency group header.
        ->assertSee('Today')
        ->assertNoAccessibilityIssues();

    // Re-audit the results against the dark palette (localStorage before the
    // class, so the appearance controller doesn't re-resolve to light mid-audit).
    $page->script(<<<'JS'
    () => {
        localStorage.setItem('appearance', 'dark');
        document.documentElement.classList.add('dark');
        document.documentElement.style.colorScheme = 'dark';
    }
    JS);

    $page->wait(0.5)
        ->assertPresent('[data-test="search-result"] mark')
        ->assertNoAccessibilityIssues();
});

test('the channel facet promotes to a chip and drives the scoped reload', function (): void {
    ['owner' => $alice] = searchPageWithMatches();

    signInThroughBrowser($alice)
        ->click('@masthead-search')
        ->assertPathContains('/search')
        ->type('@search-input', 'quokka')
        ->wait(0.8)
        ->assertPresent('[data-test="search-result"]')
        // Open the channel picker and choose the first channel.
        ->click('@facet-channel-picker')
        ->wait(0.3)
        ->assertPresent('[data-test="facet-channel-option"]')
        ->click('[data-test="facet-channel-option"]')
        ->wait(0.8)
        // The applied channel facet renders as a filled chip with a remove control.
        ->assertPresent('[data-test="facet-channel"]')
        ->assertPresent('[data-test="search-result"]');
});

test('the zero-result state names the active filters and offers both escapes', function (): void {
    ['owner' => $alice] = searchPageWithMatches();

    signInThroughBrowser($alice)
        ->click('@masthead-search')
        ->assertPathContains('/search')
        // Filter to a channel, then search a term no message matches, so the
        // zero-result state renders with its channel-scoped escapes.
        ->type('@search-input', 'quokka')
        ->wait(0.8)
        ->click('@facet-channel-picker')
        ->wait(0.3)
        ->click('[data-test="facet-channel-option"]')
        ->wait(0.8)
        ->type('@search-input', 'zzzznomatchzzzz')
        ->wait(0.8)
        ->assertPresent('[data-test="search-empty"]')
        ->assertPresent('[data-test="search-clear-filters"]')
        ->assertPresent('[data-test="search-all-channels"]');
});
