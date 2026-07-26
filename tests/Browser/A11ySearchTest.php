<?php

declare(strict_types=1);

use App\Actions\Channels\JoinChannel;
use App\Actions\Teams\CreateTeam;
use App\Enums\TeamRole;
use App\Models\Channel;
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

    // The highlight styling is scoped CSS reaching through `<SafeHtml>`'s root
    // into markup it rendered; assert it actually lands, since a browser-default
    // <mark> would still satisfy the presence check above while losing the brass
    // palette this test audits for contrast.
    $highlight = $page->script(<<<'JS'
    () => {
        const mark = document.querySelector('[data-test="search-result"] mark');
        const styles = getComputedStyle(mark);

        return {
            fontWeight: styles.fontWeight,
            borderRadius: styles.borderRadius,
        };
    }
    JS);

    expect($highlight)->toBe(['fontWeight' => '600', 'borderRadius' => '3px']);

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

test('a member of a single team never sees the workspace scope control', function (): void {
    // A fresh user belongs only to their own personal team, so the scope control
    // — which widens search across teams — has nothing to widen to and stays hidden.
    $solo = User::factory()->create();

    signInThroughBrowser($solo)
        ->click('@masthead-search')
        ->assertPathContains('/search')
        ->assertMissing('[data-test="scope-control"]');
});

test('a multi-team member can widen the search to all workspaces and see cross-team tags', function (): void {
    ['owner' => $alice, 'member' => $bob, 'channel' => $general] = browserTeamWithChannel();

    Message::factory()->create([
        'channel_id' => $general->id,
        'user_id' => $bob->id,
        'body' => 'the quokka danced in acme today',
    ]);

    // Alice also belongs to a second team with its own matching message.
    $beta = app(CreateTeam::class)->handle(User::factory()->create(), 'Beta');
    $betaGeneral = Channel::where('team_id', $beta->id)
        ->where('slug', Channel::GENERAL_SLUG)
        ->firstOrFail();
    $beta->memberships()->create(['user_id' => $alice->id, 'role' => TeamRole::Member]);
    app(JoinChannel::class)->handle($betaGeneral, $alice);
    Message::factory()->create([
        'channel_id' => $betaGeneral->id,
        'user_id' => $alice->id,
        'body' => 'the quokka appeared in beta too',
    ]);

    signInThroughBrowser($alice)
        ->click('@masthead-search')
        ->assertPathContains('/search')
        ->type('@search-input', 'quokka')
        ->wait(0.8)
        // Team scope: only Acme's match, no cross-team tag.
        ->assertPresent('[data-test="scope-control"]')
        ->assertMissing('[data-test="result-workspace-tag"]')
        // Widen to all workspaces: Beta's match joins, tagged with its workspace.
        ->click('@scope-all')
        ->wait(0.8)
        ->assertPresent('[data-test="result-workspace-tag"]')
        ->assertSee('Beta');
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

test('the author facet applies and removes, and a date preset applies', function (): void {
    ['owner' => $alice] = searchPageWithMatches();

    signInThroughBrowser($alice)
        ->click('@masthead-search')
        ->assertPathContains('/search')
        ->type('@search-input', 'quokka')
        ->wait(0.8)
        ->assertPresent('[data-test="search-result"]')
        // Pick an author: filter to one match, then select it — the applied
        // facet renders as a chip.
        ->click('@facet-author-picker')
        ->wait(0.3)
        ->type('@facet-author-filter', 'Bob')
        ->wait(0.3)
        ->assertPresent('[data-test="facet-author-option"]')
        ->click('[data-test="facet-author-option"]')
        ->wait(0.8)
        ->assertPresent('[data-test="facet-author"]')
        // Remove it via the chip's control; the picker pill returns.
        ->click('[data-test="facet-author"] button')
        ->wait(0.8)
        ->assertMissing('[data-test="facet-author"]')
        ->assertPresent('[data-test="facet-author-picker"]')
        // Apply a date preset: the date facet renders as a chip.
        ->click('@facet-date-picker')
        ->wait(0.3)
        ->assertPresent('[data-test="facet-date-preset-today"]')
        ->click('[data-test="facet-date-preset-today"]')
        ->wait(0.8)
        ->assertPresent('[data-test="facet-date"]');
});

test('the facet pickers are comboboxes that keep the open popup accessible in both themes', function (): void {
    ['owner' => $alice] = searchPageWithMatches();

    $page = signInThroughBrowser($alice)
        ->click('@masthead-search')
        ->assertPathContains('/search')
        ->type('@search-input', 'quokka')
        ->wait(0.8)
        ->assertPresent('[data-test="search-result"]')
        // The pill opens a real combobox: a filter field beside a listbox of
        // options, never a text input buried inside a menu (#451).
        ->click('@facet-author-picker')
        ->wait(0.3)
        ->assertPresent('[data-test="facet-author-filter"][role="combobox"]')
        ->assertPresent(
            '[role="listbox"] [data-test="facet-author-option"][role="option"]',
        )
        ->assertNoAccessibilityIssues();

    // The field names the list it controls, and the arrow keys rove the
    // highlight through that list without focus ever leaving the field.
    $wiring = $page->keys('@facet-author-filter', ['ArrowDown'])->script(<<<'JS'
    () => {
        const input = document.querySelector('[data-test="facet-author-filter"]');
        const listbox = document.querySelector('[role="listbox"]');
        const active = document.getElementById(
            input.getAttribute('aria-activedescendant') ?? '',
        );

        return {
            controlsTheList: input.getAttribute('aria-controls') === listbox.id,
            expanded: input.getAttribute('aria-expanded'),
            keepsFocus: document.activeElement === input,
            activeRole: active?.getAttribute('role') ?? null,
            activeIsHighlighted: active?.hasAttribute('data-highlighted') ?? false,
        };
    }
    JS);

    expect($wiring)->toBe([
        'controlsTheList' => true,
        'expanded' => 'true',
        'keepsFocus' => true,
        'activeRole' => 'option',
        'activeIsHighlighted' => true,
    ]);

    // A filter that matches nothing empties the listbox and says so — audited
    // too, since an empty list is the state a picker is easiest to break in.
    $page->type('@facet-author-filter', 'zzzznobodyzzzz')
        ->wait(0.3)
        ->assertMissing('[data-test="facet-author-option"]')
        ->assertSee('No people match')
        ->assertNoAccessibilityIssues();

    // Type to filter, then Enter applies the highlighted option as a chip.
    $page->type('@facet-author-filter', 'Bob')
        ->wait(0.3)
        ->keys('@facet-author-filter', ['Enter'])
        ->wait(0.8)
        ->assertPresent('[data-test="facet-author"]')
        ->assertMissing('[data-test="facet-author-filter"]');

    // Escape dismisses a picker without applying anything.
    $page->click('@facet-channel-picker')
        ->wait(0.3)
        ->assertPresent('[data-test="facet-channel-filter"][role="combobox"]')
        ->keys('@facet-channel-filter', ['Escape'])
        ->wait(0.5)
        ->assertMissing('[data-test="facet-channel-filter"]')
        ->assertMissing('[data-test="facet-channel"]');

    // Re-audit the open picker against the dark palette (localStorage before
    // the class, so the appearance controller doesn't re-resolve to light
    // mid-audit) — the popup carries its own surface and border tokens.
    $page->script(<<<'JS'
    () => {
        localStorage.setItem('appearance', 'dark');
        document.documentElement.classList.add('dark');
        document.documentElement.style.colorScheme = 'dark';
    }
    JS);

    $page->wait(0.5)
        ->assertPresent('html.dark')
        ->click('@facet-channel-picker')
        ->wait(0.3)
        ->assertPresent('[data-test="facet-channel-option"]')
        ->assertNoAccessibilityIssues();
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
