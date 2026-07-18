<?php

declare(strict_types=1);

use App\Models\Channel;
use App\Models\Team;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->team = Team::factory()->create();
    $this->bot = User::factory()->bot($this->team)->create();
    $this->channel = Channel::factory()->for($this->team)->create();
    $this->channel->channelMembers()->create(['user_id' => $this->bot->id]);
});

it('rejects an unauthenticated request with 401', function (): void {
    $this->getJson('/api/v1/channels')
        ->assertUnauthorized();
});

it('404s the whole surface when integrations are disabled', function (): void {
    config(['integrations.enabled' => false]);

    Sanctum::actingAs($this->bot, ['channels:read']);

    $this->getJson('/api/v1/channels')
        ->assertNotFound();
});

it('allows a request whose token carries the required scope', function (): void {
    Sanctum::actingAs($this->bot, ['channels:read']);

    $this->getJson('/api/v1/channels')
        ->assertOk()
        ->assertJsonPath('data.0.id', $this->channel->id);
});

it('refuses a request whose token lacks the required scope with 403', function (): void {
    Sanctum::actingAs($this->bot, ['messages:read']);

    $this->getJson('/api/v1/channels')
        ->assertForbidden();
});

it('throttles per token and returns 429 with a Retry-After header when over the limit', function (): void {
    config(['integrations.api_rate_limit' => 1]);

    Sanctum::actingAs($this->bot, ['channels:read']);

    $this->getJson('/api/v1/channels')->assertOk();

    $this->getJson('/api/v1/channels')
        ->assertStatus(429)
        ->assertHeader('Retry-After');
});

it('authenticates a real bearer token and enforces its abilities', function (): void {
    $token = $this->bot->createToken('CI', ['channels:read'])->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/channels')->assertOk();

    // The same token lacks messages:write, so a write is refused.
    $this->withToken($token)
        ->postJson("/api/v1/channels/{$this->channel->id}/messages", ['body' => 'hi'])
        ->assertForbidden();
});
