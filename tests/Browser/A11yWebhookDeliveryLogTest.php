<?php

declare(strict_types=1);

use App\Enums\WebhookSubscriptionStatus;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;

/**
 * The outgoing-webhook detail page's axe coverage, added with the manual replay
 * action (#390).
 *
 * The replay button is the page's first per-row control, and a row control is
 * where accessible names go wrong: "Replay" repeated down a table names every
 * button identically, so each one carries a row-specific label instead. That
 * label has to keep the visible word "Replay" in it (WCAG 2.5.3, label in name),
 * which is what the script assertion below pins.
 *
 * The log mixes both outcomes on purpose: a delivery that succeeded once and the
 * two failed attempts that eventually disabled the subscription, so the audit
 * covers the success colour beside the destructive one (#1064).
 */
test('the webhook delivery log passes the axe audit in either theme', function (): void {
    ['owner' => $alice, 'team' => $team] = browserTeamWithChannel();

    $subscription = WebhookSubscription::factory()->for($team)->create([
        'name' => 'Ops mirror',
        'url' => 'https://ops.example.test/desk',
        'status' => WebhookSubscriptionStatus::Disabled,
        'disabled_at' => now(),
        'consecutive_failures' => 5,
    ]);

    // Only the failed attempt kept an envelope, so the replay button stays a
    // single-row control: the earlier success predates envelope retention.
    $replayable = WebhookDelivery::factory()->for($subscription, 'subscription')->failed()->create();
    WebhookDelivery::factory()->for($subscription, 'subscription')->failed()->withoutEnvelope()->create();
    WebhookDelivery::factory()->for($subscription, 'subscription')->withoutEnvelope()->create();

    $page = signInThroughBrowser($alice)
        ->navigate("/settings/teams/{$team->slug}/integrations/webhooks/{$subscription->id}")
        ->assertSee('Recent deliveries')
        ->assertPresent('[data-test="auto-disable-banner"]')
        ->assertPresent("[data-test=\"replay-delivery-{$replayable->id}\"]")
        ->assertNoAccessibilityIssues();

    // Exactly one of the three logged attempts kept an envelope, so exactly one
    // offers the button — and its accessible name contains its visible text.
    $page->assertScript(<<<'JS'
    (() => {
        const buttons = [...document.querySelectorAll('[data-test^="replay-delivery-"]')];

        return buttons.length === 1
            && buttons.every((button) => button.getAttribute('aria-label').includes(button.textContent.trim()));
    })()
    JS, true);

    switchToDarkTheme($page)
        ->assertNoAccessibilityIssues();
});
