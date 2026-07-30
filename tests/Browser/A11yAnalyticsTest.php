<?php

declare(strict_types=1);

use App\Models\Attachment;

/**
 * The analytics dashboard's axe coverage (#529).
 *
 * The storage read-out added here is the page's first progress indicator, and a
 * bare styled `<div>` bar carries no meaning at all to a screen reader — so the
 * audit exists to hold its `progressbar` role, its accessible name, and the tone
 * it switches to once a workspace fills up, in both themes.
 */
test('the analytics dashboard passes the axe audit in either theme', function (): void {
    config(['attachments.storage_quota_mb' => 4]);

    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    Attachment::factory()->for($channel)->create(['size_bytes' => 1024 * 1024]);

    $page = signInThroughBrowser($alice)
        ->navigate("/settings/teams/{$team->slug}/analytics")
        ->assertSee('Storage')
        ->assertPresent('[data-test="analytics-storage"]')
        ->assertNoAccessibilityIssues();

    switchToDarkTheme($page)
        ->assertNoAccessibilityIssues();
});

/**
 * A workspace over its quota swaps the muted tone for the destructive one, which
 * is the only state of the read-out drawn in a colour contrast could fail on.
 */
test('a workspace over its storage quota still passes the axe audit', function (): void {
    config(['attachments.storage_quota_mb' => 1]);

    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    Attachment::factory()->for($channel)->create(['size_bytes' => 2 * 1024 * 1024]);

    $page = signInThroughBrowser($alice)
        ->navigate("/settings/teams/{$team->slug}/analytics")
        ->assertSee('200% used')
        ->assertSee('1 MB over the limit')
        ->assertNoAccessibilityIssues();

    switchToDarkTheme($page)
        ->assertNoAccessibilityIssues();
});
