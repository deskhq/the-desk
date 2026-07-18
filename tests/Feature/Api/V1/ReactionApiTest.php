<?php

declare(strict_types=1);

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
    $this->message = Message::factory()->for($this->channel)->for($this->bot, 'user')->create();
});

it('adds a reaction and is idempotent', function (): void {
    Sanctum::actingAs($this->bot, ['reactions:write']);

    $url = "/api/v1/channels/{$this->channel->id}/messages/{$this->message->id}/reactions/".rawurlencode('👍');

    $this->putJson($url)->assertNoContent();
    $this->putJson($url)->assertNoContent();

    expect($this->message->reactions()->where('emoji', '👍')->count())->toBe(1);
});

it('removes a reaction and is idempotent', function (): void {
    $this->message->reactions()->create(['user_id' => $this->bot->id, 'emoji' => '👍']);

    Sanctum::actingAs($this->bot, ['reactions:write']);

    $url = "/api/v1/channels/{$this->channel->id}/messages/{$this->message->id}/reactions/".rawurlencode('👍');

    $this->deleteJson($url)->assertNoContent();
    // Removing again is a no-op.
    $this->deleteJson($url)->assertNoContent();

    expect($this->message->reactions()->where('emoji', '👍')->exists())->toBeFalse();
});

it('rejects a custom emoji shortcode that does not exist', function (): void {
    Sanctum::actingAs($this->bot, ['reactions:write']);

    $url = "/api/v1/channels/{$this->channel->id}/messages/{$this->message->id}/reactions/".rawurlencode(':nope:');

    $this->putJson($url)
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('emoji');
});

it('404s adding a reaction on a channel the bot is not a member of', function (): void {
    $other = Channel::factory()->for($this->team)->create();
    $foreignMessage = Message::factory()->for($other)->for($this->bot, 'user')->create();

    Sanctum::actingAs($this->bot, ['reactions:write']);

    $url = "/api/v1/channels/{$other->id}/messages/{$foreignMessage->id}/reactions/".rawurlencode('👍');

    $this->putJson($url)->assertNotFound();
});

it('forbids reacting in an archived channel', function (): void {
    $this->channel->update(['archived_at' => now()]);

    Sanctum::actingAs($this->bot, ['reactions:write']);

    $url = "/api/v1/channels/{$this->channel->id}/messages/{$this->message->id}/reactions/".rawurlencode('👍');

    $this->deleteJson($url)->assertForbidden();
});
