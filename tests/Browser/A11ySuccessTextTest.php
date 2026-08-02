<?php

declare(strict_types=1);

use App\Models\IncomingWebhook;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;

/**
 * The "everything is fine" green, audited on every shape it takes. It is the
 * counterpart of the destructive-text audit (#678, #717): the integrations
 * surfaces are the only place success is spelled in colour, and it is spelled
 * small — the "Active" pills are text-xs and the delivery log's success cell is
 * a 12px monospace figure, so all of it needs 4.5:1 rather than the large-text
 * 3:1 (#1064).
 */
test('success status text passes the axe audit in either theme', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    $subscription = WebhookSubscription::factory()->create([
        'team_id' => $team->id,
        'created_by' => $alice->id,
        'name' => 'Ops mirror',
        'url' => 'https://ops.example.test/desk',
    ]);

    IncomingWebhook::factory()->create([
        'team_id' => $team->id,
        'channel_id' => $channel->id,
        'created_by' => $alice->id,
        'name' => 'Deploy feed',
    ]);

    WebhookDelivery::factory()->for($subscription, 'subscription')->create();

    // Both racks on the index list an "Active" pill; the detail page repeats it
    // beside the subscription name and paints the delivery's response status in
    // the same green.
    $index = signInThroughBrowser($alice)
        ->navigate("/settings/teams/{$team->slug}/integrations")
        ->assertPresent("[data-test=\"outgoing-row-{$subscription->id}\"]")
        ->assertSee('Active')
        ->assertNoAccessibilityIssues();

    switchToDarkTheme($index)
        ->assertNoAccessibilityIssues();

    $detail = signInThroughBrowser($alice)
        ->navigate("/settings/teams/{$team->slug}/integrations/webhooks/{$subscription->id}")
        ->assertSee('Recent deliveries')
        ->assertSee('Active')
        ->assertNoAccessibilityIssues();

    switchToDarkTheme($detail)
        ->assertNoAccessibilityIssues();
});
