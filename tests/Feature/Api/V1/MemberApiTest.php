<?php

declare(strict_types=1);

use App\Enums\AuditAction;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\Team;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->team = Team::factory()->create();
    $this->bot = User::factory()->bot($this->team)->create();
    $this->channel = Channel::factory()->for($this->team)->private()->create();
    $this->channel->channelMembers()->create(['user_id' => $this->bot->id]);

    $this->human = User::factory()->create();
    $this->team->members()->attach($this->human, ['role' => TeamRole::Member->value]);
});

it('lists channel members', function (): void {
    $this->channel->channelMembers()->create(['user_id' => $this->human->id]);

    Sanctum::actingAs($this->bot, ['members:read']);

    $this->getJson("/api/v1/channels/{$this->channel->id}/members")
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('adds a team member to a private channel and audits it', function (): void {
    Sanctum::actingAs($this->bot, ['members:write']);

    $this->postJson("/api/v1/channels/{$this->channel->id}/members", ['user_id' => $this->human->id])
        ->assertCreated()
        ->assertJsonPath('data.id', $this->human->id);

    expect($this->channel->channelMembers()->where('user_id', $this->human->id)->exists())->toBeTrue();

    $this->assertDatabaseHas('activity_log', [
        'team_id' => $this->team->id,
        'event' => AuditAction::ChannelMemberAdded->value,
        'causer_id' => $this->bot->id,
    ]);
});

it('adds a team bot to a private channel', function (): void {
    $otherBot = User::factory()->bot($this->team)->create(['name' => 'Standup Bot']);

    Sanctum::actingAs($this->bot, ['members:write']);

    $this->postJson("/api/v1/channels/{$this->channel->id}/members", ['user_id' => $otherBot->id])
        ->assertCreated()
        ->assertJsonPath('data.id', $otherBot->id);

    expect($this->channel->channelMembers()->where('user_id', $otherBot->id)->exists())->toBeTrue();
});

it('rejects adding a bot owned by another team', function (): void {
    $foreignBot = User::factory()->bot(Team::factory()->create())->create();

    Sanctum::actingAs($this->bot, ['members:write']);

    $this->postJson("/api/v1/channels/{$this->channel->id}/members", ['user_id' => $foreignBot->id])
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('user_id');
});

it('rejects adding a user who is not in the team', function (): void {
    $outsider = User::factory()->create();

    Sanctum::actingAs($this->bot, ['members:write']);

    $this->postJson("/api/v1/channels/{$this->channel->id}/members", ['user_id' => $outsider->id])
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('user_id');
});

it('forbids managing members on a public channel', function (): void {
    $public = Channel::factory()->for($this->team)->create();
    $public->channelMembers()->create(['user_id' => $this->bot->id]);

    Sanctum::actingAs($this->bot, ['members:write']);

    $this->postJson("/api/v1/channels/{$public->id}/members", ['user_id' => $this->human->id])
        ->assertForbidden();
});

it('removes a member from a private channel and audits it', function (): void {
    $this->channel->channelMembers()->create(['user_id' => $this->human->id]);

    Sanctum::actingAs($this->bot, ['members:write']);

    $this->deleteJson("/api/v1/channels/{$this->channel->id}/members/{$this->human->id}")
        ->assertNoContent();

    expect($this->channel->channelMembers()->where('user_id', $this->human->id)->exists())->toBeFalse();

    $this->assertDatabaseHas('activity_log', [
        'team_id' => $this->team->id,
        'event' => AuditAction::ChannelMemberRemoved->value,
    ]);
});

it('404s removing a user who is not a member', function (): void {
    Sanctum::actingAs($this->bot, ['members:write']);

    $this->deleteJson("/api/v1/channels/{$this->channel->id}/members/{$this->human->id}")
        ->assertNotFound();
});

it('forbids removing a member from a public channel', function (): void {
    $public = Channel::factory()->for($this->team)->create();
    $public->channelMembers()->create(['user_id' => $this->bot->id]);
    $public->channelMembers()->create(['user_id' => $this->human->id]);

    Sanctum::actingAs($this->bot, ['members:write']);

    $this->deleteJson("/api/v1/channels/{$public->id}/members/{$this->human->id}")
        ->assertForbidden();
});
