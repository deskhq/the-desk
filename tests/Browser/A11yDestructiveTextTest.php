<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use App\Models\WebhookSubscription;

/**
 * Seed a workspace holding an auto-disabled webhook subscription, so its detail
 * page renders the two shapes destructive copy takes outside the
 * `linkDestructive` <Button> covered by #678: the "Auto-disabled" pill and the
 * auto-disable banner, both painting destructive text on a translucent
 * `bg-destructive/10` tint composited over the settings surface (#717).
 *
 * @return array{owner: User, team: Team, subscription: WebhookSubscription}
 */
function browserTeamWithDisabledWebhook(): array
{
    ['owner' => $alice, 'team' => $team] = browserTeamWithChannel();

    $subscription = WebhookSubscription::factory()->disabled()->create([
        'team_id' => $team->id,
        'created_by' => $alice->id,
        'name' => 'Deploy notifier',
    ]);

    return ['owner' => $alice, 'team' => $team, 'subscription' => $subscription];
}

test('destructive status text passes the axe audit in either theme', function (): void {
    [
        'owner' => $alice,
        'team' => $team,
        'subscription' => $subscription,
    ] = browserTeamWithDisabledWebhook();

    $page = signInThroughBrowser($alice)
        ->navigate("/settings/teams/{$team->slug}/integrations/webhooks/{$subscription->id}")
        ->assertSee('Auto-disabled')
        // The banner is the destructive text-sm copy on the tinted fill; the pill
        // is the same token at text-xs. Both are what the audit exists to guard.
        ->assertPresent('[data-test="auto-disable-banner"]')
        ->assertNoAccessibilityIssues();

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
