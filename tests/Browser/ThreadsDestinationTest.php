<?php

declare(strict_types=1);

use App\Actions\Channels\MarkThreadRead;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;

/**
 * The Threads destination: the inbox as a dock panel, its server-side filter, and
 * the bulk "mark all read".
 *
 * What these guard is the seam a feature test cannot reach: the panel's props ride
 * on `?nav=threads`, and opening the destination from the rail is a client-side
 * visit, so the panel has to ask for them itself. If that request went out without
 * the destination pinned the panel would sit on its skeleton forever, and every
 * server-side assertion would still pass.
 */

/**
 * Two threads Alice follows in #general: one Bob has replied to since she last
 * looked, and one she is caught up on.
 *
 * @return array{owner: User, team: Team, channel: Channel, unread: Message, read: Message}
 */
function threadsPanelInbox(): array
{
    ['owner' => $alice, 'member' => $bob, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    $unread = Message::factory()->for($channel)->for($alice)->create([
        'body' => 'Shall we move Thursday to 3pm?',
        'reply_count' => 1,
        'last_reply_at' => now(),
    ]);
    Message::factory()->for($channel)->for($bob)->inThread($unread)->create();

    $read = Message::factory()->for($channel)->for($alice)->create([
        'body' => 'Thanks everyone for the release push.',
        'reply_count' => 1,
        'last_reply_at' => now()->subHour(),
    ]);
    Message::factory()->for($channel)->for($bob)->inThread($read)->create();
    app(MarkThreadRead::class)->handle($read, $alice);

    return [
        'owner' => $alice,
        'team' => $team,
        'channel' => $channel,
        'unread' => $unread,
        'read' => $read,
    ];
}

/**
 * A script resolving once the panel is rendering exactly `$count` thread cards.
 *
 * Polled rather than read once: the panel fetches its own props with
 * `reset: ['threads']`, so the list is momentarily empty between the first card
 * appearing and the fresh page merging in, and a one-shot read on a loaded
 * parallel runner lands inside that window (#965).
 */
function threadCardsSettle(int $count): string
{
    return elementCountSettles('[data-test="thread-inbox-item"]', $count);
}

/**
 * Whether the panel's own text carries a fragment.
 *
 * Scoped to the panel on purpose: the channel behind it stays live, and these
 * threads are its messages — a page-wide `assertSee` would pass on the timeline's
 * copy of the body and its own "1 reply" affordance, proving nothing about the
 * panel or its filter.
 */
function threadsPanelShows(string $text): string
{
    $needle = json_encode($text, JSON_THROW_ON_ERROR);

    return <<<JS
    (() => document.querySelector('[data-test="destination-panel-threads"]')?.innerText.includes({$needle}) ?? false)()
    JS;
}

test('the rail opens the inbox in the panel, filtered to what is unread', function (): void {
    ['owner' => $alice] = threadsPanelInbox();

    $page = signInThroughBrowser($alice)
        ->resize(1280, 900)
        ->click('@rail-destination-threads')
        ->assertPresent('@thread-inbox-item')
        // The panel fetched its own props: the unread thread is here with the
        // count of what is new, and the caught-up one is filtered out.
        ->assertScript(threadCardsSettle(1), true)
        // Negative control: the poll is a real assertion, not one that reports
        // true for anything. Pointed at a total the inbox never reaches it runs
        // out of frames and says so.
        ->assertScript(threadCardsSettle(99), false)
        ->assertScript(threadsPanelShows('Shall we move Thursday to 3pm?'), true)
        ->assertScript(threadsPanelShows('Thanks everyone for the release push.'), false)
        ->assertScript(threadsPanelShows('1 new reply'), true)
        ->assertPresent('@thread-unread-dot');

    // The default filter stays off the URL, so the everyday address is the plain
    // pinned destination.
    $page->assertScript(queryParamSettles('nav', 'threads'), true)
        ->assertQueryStringMissing('filter');

    suppressTransitions($page)->assertNoAccessibilityIssues();

    // Re-audit against the dark palette.
    switchToDarkTheme($page)->assertNoAccessibilityIssues();
});

test('the All pill brings back the caught-up threads, server-side and shareable', function (): void {
    ['owner' => $alice] = threadsPanelInbox();

    $page = signInThroughBrowser($alice)
        ->resize(1280, 900)
        ->click('@rail-destination-threads')
        ->assertPresent('@thread-inbox-item')
        ->click('@threads-filter-all')
        // Two cards now, which only a fresh server page can produce: the read
        // thread was never in the client's list to un-filter.
        ->assertScript(threadsPanelShows('Thanks everyone for the release push.'), true)
        ->assertScript(threadCardsSettle(2), true);

    $page->assertScript(queryParamSettles('filter', 'all'), true)
        ->assertQueryStringHas('filter', 'all')
        // The caught-up card shows the thread's whole length, not a new-reply count.
        ->assertScript(threadsPanelShows('1 reply'), true);

    // Audited here as well as on the unread view: the caught-up card is a
    // different treatment (plain outline, dimmer copy) and carries its own
    // contrast risk. The pills swap foreground and background on `aria-pressed`,
    // so the audit has to wait out that tween like the theme flip does (#965).
    suppressTransitions($page)->assertNoAccessibilityIssues();
});

test('a filter deep link renders the panel on a cold load, with no client fetch', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel, 'read' => $read] = threadsPanelInbox();

    signInThroughBrowser($alice)
        ->resize(1280, 900)
        ->navigate(browserChannelUrl($team, $channel).'?nav=threads&filter=all')
        ->assertPresent('@thread-inbox-item')
        ->assertScript(threadCardsSettle(2), true)
        ->assertScript(
            '(() => document.querySelector(\'[data-test="threads-filter-all"]\').getAttribute("aria-pressed"))()',
            'true',
        )
        ->assertScript(threadsPanelShows($read->body), true);
});

test('mark all read empties the inbox and drops the rail dot', function (): void {
    ['owner' => $alice] = threadsPanelInbox();

    signInThroughBrowser($alice)
        ->resize(1280, 900)
        // The unread thread dots the rail glyph before anything is cleared.
        ->assertPresent('@rail-threads-unread-dot')
        ->click('@rail-destination-threads')
        ->assertPresent('@threads-mark-all-read')
        ->click('@threads-mark-all-read')
        // The redirect back re-renders the route, so the emptied inbox, the zeroed
        // tally and the dropped dot all arrive as fresh props.
        ->assertPresent('@threads-empty')
        ->assertScript(threadCardsSettle(0), true)
        ->assertNotPresent('@rail-threads-unread-dot')
        ->assertNotPresent('@threads-unread-count');
});
