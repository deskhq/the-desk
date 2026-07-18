<?php

declare(strict_types=1);

use App\Actions\Integrations\MintPersonalAccessToken;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\Message;
use App\Models\PersonalAccessToken;
use App\Models\Team;
use App\Models\User;

/**
 * Mint a real, team-bound human personal access token and return its plaintext
 * bearer value, so the tests exercise the true `currentAccessToken()->team_id`
 * path rather than Sanctum's team-less transient token.
 *
 * @param  list<string>  $abilities
 */
function humanToken(User $user, Team $team, array $abilities): string
{
    return app(MintPersonalAccessToken::class)
        ->handle($user, $team, 'CLI', $abilities)
        ->plainTextToken;
}

beforeEach(function (): void {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user, ['role' => TeamRole::Member->value]);

    $this->channel = Channel::factory()->for($this->team)->create();
    $this->channel->channelMembers()->create(['user_id' => $this->user->id]);
});

it('lists the channels in the token’s bound team a human PAT may view', function (): void {
    $token = humanToken($this->user, $this->team, ['channels:read']);

    $this->withToken($token)
        ->getJson('/api/v1/channels')
        ->assertOk()
        ->assertJsonFragment(['id' => $this->channel->id]);
});

it('confines the token to its bound team, even across the human’s other teams', function (): void {
    $otherTeam = Team::factory()->create();
    $otherTeam->members()->attach($this->user, ['role' => TeamRole::Admin->value]);
    $otherChannel = Channel::factory()->for($otherTeam)->create();
    $otherChannel->channelMembers()->create(['user_id' => $this->user->id]);

    $token = humanToken($this->user, $this->team, ['channels:read', 'messages:write']);

    $this->withToken($token)
        ->getJson("/api/v1/channels/{$otherChannel->id}")
        ->assertNotFound();

    $this->withToken($token)
        ->postJson("/api/v1/channels/{$otherChannel->id}/messages", ['body' => 'hi'])
        ->assertNotFound();
});

it('refuses a scope the token was not granted', function (): void {
    $token = humanToken($this->user, $this->team, ['channels:read']);

    $this->withToken($token)
        ->postJson("/api/v1/channels/{$this->channel->id}/messages", ['body' => 'hi'])
        ->assertForbidden();
});

it('403s once the token’s bound team has been removed', function (): void {
    $token = humanToken($this->user, $this->team, ['channels:read']);

    PersonalAccessToken::query()->update(['team_id' => null]);

    $this->withToken($token)
        ->getJson('/api/v1/channels')
        ->assertForbidden();
});

it('posts a message authored by the human', function (): void {
    $token = humanToken($this->user, $this->team, ['messages:write']);

    $this->withToken($token)
        ->postJson("/api/v1/channels/{$this->channel->id}/messages", ['body' => 'shipped'])
        ->assertCreated()
        ->assertJsonPath('data.author.id', $this->user->id)
        ->assertJsonPath('data.author.type', 'human');
});

it('forbids posting to a public channel the human has not joined', function (): void {
    $unjoined = Channel::factory()->for($this->team)->create();

    $token = humanToken($this->user, $this->team, ['messages:write']);

    $this->withToken($token)
        ->postJson("/api/v1/channels/{$unjoined->id}/messages", ['body' => 'hi'])
        ->assertForbidden();
});

it('404s a private channel the human does not belong to', function (): void {
    $private = Channel::factory()->for($this->team)->private()->create();

    $token = humanToken($this->user, $this->team, ['channels:read']);

    $this->withToken($token)
        ->getJson("/api/v1/channels/{$private->id}")
        ->assertNotFound();
});

it('lets the human edit their own message but not another member’s', function (): void {
    $own = Message::factory()->for($this->channel)->for($this->user, 'user')->create(['body' => 'mine']);

    $other = User::factory()->create();
    $this->team->members()->attach($other, ['role' => TeamRole::Member->value]);
    $theirs = Message::factory()->for($this->channel)->for($other, 'user')->create(['body' => 'theirs']);

    $token = humanToken($this->user, $this->team, ['messages:write']);

    $this->withToken($token)
        ->patchJson("/api/v1/channels/{$this->channel->id}/messages/{$own->id}", ['body' => 'edited'])
        ->assertOk()
        ->assertJsonPath('data.body', 'edited');

    $this->withToken($token)
        ->patchJson("/api/v1/channels/{$this->channel->id}/messages/{$theirs->id}", ['body' => 'nope'])
        ->assertForbidden();
});

it('lets the human delete their own message, a member delete no one else’s, an admin any', function (): void {
    $other = User::factory()->create();
    $this->team->members()->attach($other, ['role' => TeamRole::Member->value]);

    $own = Message::factory()->for($this->channel)->for($this->user, 'user')->create();
    $theirs = Message::factory()->for($this->channel)->for($other, 'user')->create();

    $memberToken = humanToken($this->user, $this->team, ['messages:write']);

    $this->withToken($memberToken)
        ->deleteJson("/api/v1/channels/{$this->channel->id}/messages/{$own->id}")
        ->assertNoContent();

    $this->withToken($memberToken)
        ->deleteJson("/api/v1/channels/{$this->channel->id}/messages/{$theirs->id}")
        ->assertForbidden();

    $this->team->members()->updateExistingPivot($this->user->id, ['role' => TeamRole::Admin->value]);
    $adminToken = humanToken($this->user, $this->team, ['messages:write']);

    $this->withToken($adminToken)
        ->deleteJson("/api/v1/channels/{$this->channel->id}/messages/{$theirs->id}")
        ->assertNoContent();
});

it('creates a channel as the human in the bound team', function (): void {
    $token = humanToken($this->user, $this->team, ['channels:write']);

    $this->withToken($token)
        ->postJson('/api/v1/channels', ['name' => 'launch', 'visibility' => 'public'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'launch');

    $this->assertDatabaseHas('channels', [
        'team_id' => $this->team->id,
        'name' => 'launch',
        'created_by' => $this->user->id,
    ]);
});

it('lets the channel creator archive but forbids a non-creator member', function (): void {
    $created = Channel::factory()->for($this->team)->create(['created_by' => $this->user->id]);
    $created->channelMembers()->create(['user_id' => $this->user->id]);

    $foreign = Channel::factory()->for($this->team)->create();
    $foreign->channelMembers()->create(['user_id' => $this->user->id]);

    $token = humanToken($this->user, $this->team, ['channels:write']);

    $this->withToken($token)
        ->postJson("/api/v1/channels/{$created->id}/archive")
        ->assertOk();

    $this->withToken($token)
        ->postJson("/api/v1/channels/{$foreign->id}/archive")
        ->assertForbidden();
});

it('lets a private-channel member add and remove members via PAT', function (): void {
    $private = Channel::factory()->for($this->team)->private()->create();
    $private->channelMembers()->create(['user_id' => $this->user->id]);

    $target = User::factory()->create();
    $this->team->members()->attach($target, ['role' => TeamRole::Member->value]);

    $token = humanToken($this->user, $this->team, ['members:write']);

    $this->withToken($token)
        ->postJson("/api/v1/channels/{$private->id}/members", ['user_id' => $target->id])
        ->assertCreated();

    $this->withToken($token)
        ->deleteJson("/api/v1/channels/{$private->id}/members/{$target->id}")
        ->assertNoContent();
});

it('adds and removes the human’s reaction on a message', function (): void {
    $message = Message::factory()->for($this->channel)->for($this->user, 'user')->create();

    $token = humanToken($this->user, $this->team, ['reactions:write']);

    $this->withToken($token)
        ->putJson("/api/v1/channels/{$this->channel->id}/messages/{$message->id}/reactions/%F0%9F%91%8D")
        ->assertNoContent();

    $this->assertDatabaseHas('message_reactions', [
        'message_id' => $message->id,
        'user_id' => $this->user->id,
        'emoji' => '👍',
    ]);

    $this->withToken($token)
        ->deleteJson("/api/v1/channels/{$this->channel->id}/messages/{$message->id}/reactions/%F0%9F%91%8D")
        ->assertNoContent();

    $this->assertDatabaseMissing('message_reactions', [
        'message_id' => $message->id,
        'user_id' => $this->user->id,
    ]);
});
