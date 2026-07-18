<?php

declare(strict_types=1);

use App\Enums\AuditAction;
use App\Enums\WebhookEvent;
use App\Models\AuditActivity;
use App\Models\Channel;
use App\Models\Team;
use App\Models\User;
use App\Models\WebhookSubscription;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->team = Team::factory()->create();
    $this->bot = User::factory()->bot($this->team)->create();
    $this->channel = Channel::factory()->for($this->team)->create();
});

it('lists the team’s subscriptions newest first', function (): void {
    $older = WebhookSubscription::factory()->for($this->team)->create();
    $newer = WebhookSubscription::factory()->for($this->team)->create();
    WebhookSubscription::factory()->create(); // another team's — must not leak

    Sanctum::actingAs($this->bot, ['webhooks:read']);

    $response = $this->getJson('/api/v1/webhooks')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $newer->id)
        ->assertJsonPath('data.1.id', $older->id);

    expect($response->json('data.0'))->not->toHaveKey('secret');
});

it('creates a subscription and returns the signing secret once', function (): void {
    Sanctum::actingAs($this->bot, ['webhooks:write']);

    $response = $this->postJson('/api/v1/webhooks', [
        'name' => 'CI relay',
        'url' => 'https://example.test/hooks',
        'events' => [WebhookEvent::MessageCreated->value, WebhookEvent::ReactionAdded->value],
        'channel_ids' => [$this->channel->id],
    ])->assertCreated();

    $response->assertJsonPath('data.name', 'CI relay')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.events', [WebhookEvent::MessageCreated->value, WebhookEvent::ReactionAdded->value]);

    $secret = $response->json('secret');
    expect($secret)->toStartWith('whsec_');

    $subscription = WebhookSubscription::sole();
    expect($subscription->team_id)->toBe($this->team->id)
        ->and($subscription->created_by)->toBe($this->bot->id)
        ->and($subscription->secret)->toBe($secret)
        ->and($subscription->channel_ids)->toBe([$this->channel->id]);

    expect(AuditActivity::where('event', AuditAction::WebhookSubscriptionCreated->value)->exists())->toBeTrue();
});

it('creates a subscription without a channel allow-list', function (): void {
    Sanctum::actingAs($this->bot, ['webhooks:write']);

    $this->postJson('/api/v1/webhooks', [
        'name' => 'All channels',
        'url' => 'https://example.test/hooks',
        'events' => [WebhookEvent::MessageCreated->value],
    ])->assertCreated();

    expect(WebhookSubscription::sole()->channel_ids)->toBeNull();
});

it('rejects an unknown event value', function (): void {
    Sanctum::actingAs($this->bot, ['webhooks:write']);

    $this->postJson('/api/v1/webhooks', [
        'name' => 'Bad',
        'url' => 'https://example.test/hooks',
        'events' => ['message.exploded'],
    ])->assertJsonValidationErrorFor('events.0');
});

it('rejects a channel that is not in the bot’s team', function (): void {
    $foreignChannel = Channel::factory()->create();

    Sanctum::actingAs($this->bot, ['webhooks:write']);

    $this->postJson('/api/v1/webhooks', [
        'name' => 'Bad channel',
        'url' => 'https://example.test/hooks',
        'events' => [WebhookEvent::MessageCreated->value],
        'channel_ids' => [$foreignChannel->id],
    ])->assertJsonValidationErrorFor('channel_ids');
});

it('shows a subscription with its recent delivery attempts', function (): void {
    $subscription = WebhookSubscription::factory()->for($this->team)->create();
    $subscription->deliveries()->create([
        'event_type' => WebhookEvent::MessageCreated->value,
        'event_id' => (string) Str::uuid(),
        'succeeded' => false,
        'response_status' => 500,
        'error' => 'HTTP 500',
    ]);

    Sanctum::actingAs($this->bot, ['webhooks:read']);

    $this->getJson("/api/v1/webhooks/{$subscription->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $subscription->id)
        ->assertJsonPath('data.deliveries.0.response_status', 500)
        ->assertJsonPath('data.deliveries.0.succeeded', false);
});

it('404s a subscription belonging to another team', function (): void {
    $foreign = WebhookSubscription::factory()->create();

    Sanctum::actingAs($this->bot, ['webhooks:read']);

    $this->getJson("/api/v1/webhooks/{$foreign->id}")->assertNotFound();
});

it('revokes a subscription and records the revocation', function (): void {
    $subscription = WebhookSubscription::factory()->for($this->team)->create();

    Sanctum::actingAs($this->bot, ['webhooks:write']);

    $this->deleteJson("/api/v1/webhooks/{$subscription->id}")->assertNoContent();

    expect(WebhookSubscription::find($subscription->id))->toBeNull();
    expect(AuditActivity::where('event', AuditAction::WebhookSubscriptionRevoked->value)->exists())->toBeTrue();
});

it('refuses to revoke another team’s subscription', function (): void {
    $foreign = WebhookSubscription::factory()->create();

    Sanctum::actingAs($this->bot, ['webhooks:write']);

    $this->deleteJson("/api/v1/webhooks/{$foreign->id}")->assertNotFound();
    expect(WebhookSubscription::find($foreign->id))->not->toBeNull();
});

it('enforces the read scope on listing', function (): void {
    Sanctum::actingAs($this->bot, ['messages:read']);

    $this->getJson('/api/v1/webhooks')->assertForbidden();
});

it('enforces the write scope on creation', function (): void {
    Sanctum::actingAs($this->bot, ['webhooks:read']);

    $this->postJson('/api/v1/webhooks', [
        'name' => 'x',
        'url' => 'https://example.test/hooks',
        'events' => [WebhookEvent::MessageCreated->value],
    ])->assertForbidden();
});

it('404s the whole surface when integrations are disabled', function (): void {
    config(['integrations.enabled' => false]);

    Sanctum::actingAs($this->bot, ['webhooks:read']);

    $this->getJson('/api/v1/webhooks')->assertNotFound();
});
