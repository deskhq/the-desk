<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->team = Team::factory()->create();
    $this->bot = User::factory()->bot($this->team)->create();
    $this->channel = Channel::factory()->for($this->team)->create();
    $this->channel->channelMembers()->create(['user_id' => $this->bot->id]);
});

it('lists a channel’s messages', function (): void {
    Message::factory()->for($this->channel)->for($this->bot, 'user')->create(['body' => 'Hello world']);

    Sanctum::actingAs($this->bot, ['messages:read']);

    $this->getJson("/api/v1/channels/{$this->channel->id}/messages")
        ->assertOk()
        ->assertJsonPath('data.0.body', 'Hello world')
        ->assertJsonPath('data.0.author.id', $this->bot->id)
        ->assertJsonPath('data.0.author.type', 'bot');
});

it('posts a message as the bot', function (): void {
    Sanctum::actingAs($this->bot, ['messages:write']);

    $this->postJson("/api/v1/channels/{$this->channel->id}/messages", ['body' => 'Deployed v2'])
        ->assertCreated()
        ->assertJsonPath('data.body', 'Deployed v2')
        ->assertJsonPath('data.author.id', $this->bot->id);

    $this->assertDatabaseHas('messages', [
        'channel_id' => $this->channel->id,
        'user_id' => $this->bot->id,
        'body' => 'Deployed v2',
    ]);
});

it('records which token posted the message', function (): void {
    $token = $this->bot->createToken('CI deploys', ['messages:write']);

    $this->withToken($token->plainTextToken)
        ->postJson("/api/v1/channels/{$this->channel->id}/messages", ['body' => 'Deployed v2'])
        ->assertCreated();

    // The author names the bot; only this names which of its credentials, which
    // is what makes revocation targetable.
    $this->assertDatabaseHas('messages', [
        'body' => 'Deployed v2',
        'token_id' => $token->accessToken->getKey(),
    ]);
});

it('is idempotent on a repeated client_uuid', function (): void {
    Sanctum::actingAs($this->bot, ['messages:write']);

    $uuid = (string) Str::uuid();

    $first = $this->postJson("/api/v1/channels/{$this->channel->id}/messages", ['body' => 'Once', 'client_uuid' => $uuid])->assertCreated();
    $second = $this->postJson("/api/v1/channels/{$this->channel->id}/messages", ['body' => 'Once', 'client_uuid' => $uuid])->assertCreated();

    expect($second->json('data.id'))->toBe($first->json('data.id'));
    expect(Message::query()->where('channel_id', $this->channel->id)->count())->toBe(1);
});

it('accepts a poll as the inline-reply and thread-root target', function (): void {
    $poll = Message::factory()->poll()->for($this->channel)->for($this->bot, 'user')->create();

    Sanctum::actingAs($this->bot, ['messages:write']);

    $this->postJson("/api/v1/channels/{$this->channel->id}/messages", ['body' => 'Quoting the poll', 'reply_to_id' => $poll->id])
        ->assertCreated();

    $this->postJson("/api/v1/channels/{$this->channel->id}/messages", ['body' => 'Threading off the poll', 'thread_root_id' => $poll->id])
        ->assertCreated();

    $this->assertDatabaseHas('messages', ['body' => 'Quoting the poll', 'reply_to_id' => $poll->id]);
    $this->assertDatabaseHas('messages', ['body' => 'Threading off the poll', 'thread_root_id' => $poll->id]);
});

it('rejects a system notice as the inline-reply or thread-root target', function (): void {
    $notice = Message::factory()->for($this->channel)->for($this->bot, 'user')->memberJoined()->create();

    Sanctum::actingAs($this->bot, ['messages:write']);

    $this->postJson("/api/v1/channels/{$this->channel->id}/messages", ['body' => 'nope', 'reply_to_id' => $notice->id])
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('reply_to_id');

    $this->postJson("/api/v1/channels/{$this->channel->id}/messages", ['body' => 'nope', 'thread_root_id' => $notice->id])
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('thread_root_id');
});

it('rejects an empty message body', function (): void {
    Sanctum::actingAs($this->bot, ['messages:write']);

    $this->postJson("/api/v1/channels/{$this->channel->id}/messages", ['body' => '   '])
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('body');
});

it('forbids posting to an archived channel', function (): void {
    $this->channel->update(['archived_at' => now()]);

    Sanctum::actingAs($this->bot, ['messages:write']);

    $this->postJson("/api/v1/channels/{$this->channel->id}/messages", ['body' => 'nope'])
        ->assertForbidden();
});

it('forbids posting to a channel the bot is not a member of', function (): void {
    $other = Channel::factory()->for($this->team)->create();

    Sanctum::actingAs($this->bot, ['messages:write']);

    // Not a member → the channel is invisible to the bot (404).
    $this->postJson("/api/v1/channels/{$other->id}/messages", ['body' => 'hi'])
        ->assertNotFound();
});

it('shows a message and 404s one from another channel', function (): void {
    $message = Message::factory()->for($this->channel)->for($this->bot, 'user')->create();

    $otherChannel = Channel::factory()->for($this->team)->create();
    $otherChannel->channelMembers()->create(['user_id' => $this->bot->id]);
    $foreignMessage = Message::factory()->for($otherChannel)->for($this->bot, 'user')->create();

    Sanctum::actingAs($this->bot, ['messages:read']);

    $this->getJson("/api/v1/channels/{$this->channel->id}/messages/{$message->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $message->id);

    // Message id belongs to a different channel than the one in the path.
    $this->getJson("/api/v1/channels/{$this->channel->id}/messages/{$foreignMessage->id}")
        ->assertNotFound();
});

it('edits the bot’s own message', function (): void {
    $message = Message::factory()->for($this->channel)->for($this->bot, 'user')->create(['body' => 'typo']);

    Sanctum::actingAs($this->bot, ['messages:write']);

    $this->patchJson("/api/v1/channels/{$this->channel->id}/messages/{$message->id}", ['body' => 'fixed'])
        ->assertOk()
        ->assertJsonPath('data.body', 'fixed')
        ->assertJsonPath('data.edited_at', fn ($value): bool => $value !== null);
});

it('forbids editing another author’s message', function (): void {
    $human = User::factory()->create();
    $this->team->members()->attach($human, ['role' => TeamRole::Member->value]);
    $message = Message::factory()->for($this->channel)->for($human, 'user')->create();

    Sanctum::actingAs($this->bot, ['messages:write']);

    $this->patchJson("/api/v1/channels/{$this->channel->id}/messages/{$message->id}", ['body' => 'hijack'])
        ->assertForbidden();
});

it('deletes the bot’s own message', function (): void {
    $message = Message::factory()->for($this->channel)->for($this->bot, 'user')->create();

    Sanctum::actingAs($this->bot, ['messages:write']);

    $this->deleteJson("/api/v1/channels/{$this->channel->id}/messages/{$message->id}")
        ->assertNoContent();

    $this->assertSoftDeleted('messages', ['id' => $message->id]);
});

it('forbids deleting another author’s message', function (): void {
    $human = User::factory()->create();
    $this->team->members()->attach($human, ['role' => TeamRole::Member->value]);
    $message = Message::factory()->for($this->channel)->for($human, 'user')->create();

    Sanctum::actingAs($this->bot, ['messages:write']);

    $this->deleteJson("/api/v1/channels/{$this->channel->id}/messages/{$message->id}")
        ->assertForbidden();
});
