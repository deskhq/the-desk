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
        // The bar is a styled <div>: only the progressbar role, its name, and its
        // value carry the reading to a screen reader.
        ->assertPresent('[role="progressbar"][aria-label="Storage used"][aria-valuenow="25"][aria-valuetext="1 MB of 4 MB"]')
        ->assertPresent('[data-test="analytics-storage-percent"].text-muted-foreground')
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
        // The overshoot is clamped for the bar, which cannot draw past full.
        ->assertPresent('[role="progressbar"][aria-valuenow="100"]')
        ->assertPresent('[data-test="analytics-storage-percent"].text-destructive-text')
        ->assertPresent('[data-test="analytics-storage-remaining"].text-destructive-text')
        ->assertNoAccessibilityIssues();

    switchToDarkTheme($page)
        ->assertNoAccessibilityIssues();
});
