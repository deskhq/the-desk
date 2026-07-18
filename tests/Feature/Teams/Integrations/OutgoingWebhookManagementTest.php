<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Enums\WebhookEvent;
use App\Enums\WebhookSubscriptionStatus;
use App\Models\Channel;
use App\Models\Team;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{team: Team, owner: User, member: User}
 */
function outgoingFixture(): array
{
    $team = Team::factory()->create();
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    return ['team' => $team, 'owner' => $owner, 'member' => $member];
}

it('registers an outgoing subscription scoped to a team channel', function (): void {
    ['team' => $team, 'owner' => $owner] = outgoingFixture();
    $channel = Channel::factory()->for($team)->create();

    $this->actingAs($owner)
        ->post(route('teams.integrations.webhooks.store', $team), [
            'name' => 'Ops mirror',
            'url' => 'https://ops.example.test/desk',
            'events' => [WebhookEvent::MessageCreated->value],
            'channel_ids' => [$channel->id],
        ])
        ->assertRedirect();

    $subscription = $team->webhookSubscriptions()->sole();
    expect($subscription->name)->toBe('Ops mirror')
        ->and($subscription->channel_ids)->toBe([$channel->id])
        ->and($subscription->status)->toBe(WebhookSubscriptionStatus::Active);
});

it('registers a workspace-wide subscription when no channels are given', function (): void {
    ['team' => $team, 'owner' => $owner] = outgoingFixture();

    $this->actingAs($owner)
        ->post(route('teams.integrations.webhooks.store', $team), [
            'name' => 'All events',
            'url' => 'https://ops.example.test/desk',
            'events' => [WebhookEvent::MessageCreated->value],
        ])
        ->assertRedirect();

    expect($team->webhookSubscriptions()->sole()->channel_ids)->toBeNull();
});

it('rejects a subscription scoped to a channel outside the team', function (): void {
    ['team' => $team, 'owner' => $owner] = outgoingFixture();
    $foreign = Channel::factory()->for(Team::factory()->create())->create();

    $this->actingAs($owner)
        ->post(route('teams.integrations.webhooks.store', $team), [
            'name' => 'Bad',
            'url' => 'https://ops.example.test/desk',
            'events' => [WebhookEvent::MessageCreated->value],
            'channel_ids' => [$foreign->id],
        ])
        ->assertSessionHasErrors('channel_ids');
});

it('shows a channel-scoped subscription detail with its recent delivery log', function (): void {
    ['team' => $team, 'owner' => $owner] = outgoingFixture();
    $channel = Channel::factory()->for($team)->create(['name' => 'support']);
    $subscription = WebhookSubscription::factory()->for($team)->create([
        'channel_ids' => [$channel->id],
    ]);
    WebhookDelivery::factory()->for($subscription, 'subscription')->create();
    WebhookDelivery::factory()->for($subscription, 'subscription')->failed()->create();

    $this->actingAs($owner)
        ->get(route('teams.integrations.webhooks.show', ['team' => $team->slug, 'webhookSubscription' => $subscription->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('teams/integrations/Webhook')
            ->where('detail.subscription.id', $subscription->id)
            ->has('detail.channels', 1)
            ->where('detail.channels.0.name', 'support')
            ->has('detail.deliveries', 2)
        );
});

it('shows a workspace-wide subscription detail with no channel scope', function (): void {
    ['team' => $team, 'owner' => $owner] = outgoingFixture();
    $subscription = WebhookSubscription::factory()->for($team)->create([
        'channel_ids' => null,
    ]);

    $this->actingAs($owner)
        ->get(route('teams.integrations.webhooks.show', ['team' => $team->slug, 'webhookSubscription' => $subscription->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('detail.channels', null)
        );
});

it('re-enables an auto-disabled subscription from the detail page', function (): void {
    ['team' => $team, 'owner' => $owner] = outgoingFixture();
    $subscription = WebhookSubscription::factory()->for($team)->create([
        'status' => WebhookSubscriptionStatus::Disabled,
        'consecutive_failures' => 5,
        'disabled_at' => now(),
    ]);

    $this->actingAs($owner)
        ->post(route('teams.integrations.webhooks.reenable', ['team' => $team->slug, 'webhookSubscription' => $subscription->id]))
        ->assertRedirect();

    expect($subscription->fresh()->status)->toBe(WebhookSubscriptionStatus::Active);
});

it('rotates a subscription signing secret from the detail page', function (): void {
    ['team' => $team, 'owner' => $owner] = outgoingFixture();
    $subscription = WebhookSubscription::factory()->for($team)->create();
    $oldSecret = $subscription->secret;

    $this->actingAs($owner)
        ->post(route('teams.integrations.webhooks.rotate-secret', ['team' => $team->slug, 'webhookSubscription' => $subscription->id]))
        ->assertRedirect();

    expect($subscription->fresh()->secret)->not->toBe($oldSecret);
});

it('revokes a subscription and redirects to the integrations home', function (): void {
    ['team' => $team, 'owner' => $owner] = outgoingFixture();
    $subscription = WebhookSubscription::factory()->for($team)->create();

    $this->actingAs($owner)
        ->delete(route('teams.integrations.webhooks.destroy', ['team' => $team->slug, 'webhookSubscription' => $subscription->id]))
        ->assertRedirect(route('teams.integrations.index', $team));

    $this->assertDatabaseMissing('webhook_subscriptions', ['id' => $subscription->id]);
});

it('forbids a member from viewing a subscription detail', function (): void {
    ['team' => $team, 'member' => $member] = outgoingFixture();
    $subscription = WebhookSubscription::factory()->for($team)->create();

    $this->actingAs($member)
        ->get(route('teams.integrations.webhooks.show', ['team' => $team->slug, 'webhookSubscription' => $subscription->id]))
        ->assertForbidden();
});

it('404s a subscription detail from another team', function (): void {
    ['team' => $team, 'owner' => $owner] = outgoingFixture();
    $foreign = WebhookSubscription::factory()->create();

    $this->actingAs($owner)
        ->get(route('teams.integrations.webhooks.show', ['team' => $team->slug, 'webhookSubscription' => $foreign->id]))
        ->assertNotFound();
});
