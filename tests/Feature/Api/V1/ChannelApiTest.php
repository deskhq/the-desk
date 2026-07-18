<?php

declare(strict_types=1);

use App\Enums\AuditAction;
use App\Enums\ChannelVisibility;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\Team;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->team = Team::factory()->create();
    $this->bot = User::factory()->bot($this->team)->create();
    $this->channel = Channel::factory()->for($this->team)->create(['name' => 'General Chat', 'slug' => 'general-chat']);
    $this->channel->channelMembers()->create(['user_id' => $this->bot->id]);
});

it('lists only the channels the bot belongs to', function (): void {
    // A second channel in the same team the bot is NOT a member of.
    Channel::factory()->for($this->team)->create();

    Sanctum::actingAs($this->bot, ['channels:read']);

    $this->getJson('/api/v1/channels')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->channel->id)
        ->assertJsonPath('data.0.name', 'General Chat');
});

it('shows a channel the bot belongs to', function (): void {
    Sanctum::actingAs($this->bot, ['channels:read']);

    $this->getJson("/api/v1/channels/{$this->channel->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $this->channel->id)
        ->assertJsonPath('data.visibility', ChannelVisibility::Public->value);
});

it('404s a channel the bot is not a member of', function (): void {
    $other = Channel::factory()->for($this->team)->create();

    Sanctum::actingAs($this->bot, ['channels:read']);

    $this->getJson("/api/v1/channels/{$other->id}")->assertNotFound();
});

it('404s a channel in another team', function (): void {
    $otherTeam = Team::factory()->create();
    $foreign = Channel::factory()->for($otherTeam)->create();

    Sanctum::actingAs($this->bot, ['channels:read']);

    $this->getJson("/api/v1/channels/{$foreign->id}")->assertNotFound();
});

it('creates a channel in the bot’s team and audits it', function (): void {
    Sanctum::actingAs($this->bot, ['channels:write']);

    $response = $this->postJson('/api/v1/channels', [
        'name' => 'Releases',
        'visibility' => ChannelVisibility::Private->value,
        'topic' => 'Ship logs',
    ])->assertCreated()->assertJsonPath('data.name', 'Releases');

    $channel = Channel::query()->where('team_id', $this->team->id)->where('slug', 'releases')->sole();

    // The bot is seeded as a member so it can immediately post.
    expect($channel->channelMembers()->where('user_id', $this->bot->id)->exists())->toBeTrue()
        ->and($response->json('data.is_archived'))->toBeFalse();

    $this->assertDatabaseHas('activity_log', [
        'team_id' => $this->team->id,
        'event' => AuditAction::ChannelCreated->value,
        'causer_id' => $this->bot->id,
    ]);
});

it('rejects a channel with a blank name', function (): void {
    Sanctum::actingAs($this->bot, ['channels:write']);

    $this->postJson('/api/v1/channels', [
        'name' => '',
        'visibility' => ChannelVisibility::Public->value,
    ])->assertStatus(422)->assertJsonValidationErrorFor('name');
});

it('rejects a duplicate channel name', function (): void {
    Sanctum::actingAs($this->bot, ['channels:write']);

    $this->postJson('/api/v1/channels', [
        'name' => 'General Chat',
        'visibility' => ChannelVisibility::Public->value,
    ])->assertStatus(422)->assertJsonValidationErrorFor('name');
});

it('archives a channel the bot created and audits it', function (): void {
    $channel = Channel::factory()->for($this->team)->create(['created_by' => $this->bot->id]);
    $channel->channelMembers()->create(['user_id' => $this->bot->id]);

    Sanctum::actingAs($this->bot, ['channels:write']);

    $this->postJson("/api/v1/channels/{$channel->id}/archive")
        ->assertOk()
        ->assertJsonPath('data.is_archived', true);

    $this->assertDatabaseHas('activity_log', [
        'team_id' => $this->team->id,
        'event' => AuditAction::ChannelArchived->value,
    ]);
});

it('forbids archiving a channel the bot did not create', function (): void {
    $human = User::factory()->create();
    $this->team->members()->attach($human, ['role' => TeamRole::Member->value]);
    $channel = Channel::factory()->for($this->team)->create(['created_by' => $human->id]);
    $channel->channelMembers()->create(['user_id' => $this->bot->id]);

    Sanctum::actingAs($this->bot, ['channels:write']);

    $this->postJson("/api/v1/channels/{$channel->id}/archive")->assertForbidden();
});
