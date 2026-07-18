<?php

declare(strict_types=1);

use App\Events\MessageSent;
use App\Models\Channel;
use App\Models\IncomingWebhook;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/**
 * Mint a live webhook bound to a fresh bot + channel and return its plaintext
 * token, mirroring what {@see CreateIncomingWebhook} hands the operator.
 *
 * @return array{IncomingWebhook, string}
 */
function makeWebhook(array $attributes = []): array
{
    $team = Team::factory()->create();
    $bot = User::factory()->bot($team)->create(['name' => 'Deploy Bot']);
    $channel = Channel::factory()->for($team)->create();
    $channel->channelMembers()->create(['user_id' => $bot->id]);

    $token = Str::random(48);

    $webhook = IncomingWebhook::factory()
        ->for($team)->for($channel)->for($bot, 'bot')
        ->create(array_merge(['token_hash' => IncomingWebhook::hashToken($token)], $attributes));

    return [$webhook, $token];
}

it('posts a message from a native body payload and broadcasts it', function (): void {
    Event::fake([MessageSent::class]);
    [$webhook, $token] = makeWebhook();

    $this->postJson("/webhooks/incoming/{$token}", ['body' => 'Deploy finished ✅'])
        ->assertStatus(202)
        ->assertJson(['ok' => true]);

    $this->assertDatabaseHas('messages', [
        'channel_id' => $webhook->channel_id,
        'user_id' => $webhook->bot_id,
        'body' => 'Deploy finished ✅',
    ]);

    Event::assertDispatched(MessageSent::class, fn (MessageSent $event): bool => $event->channel->id === $webhook->channel_id
        && $event->message->body === 'Deploy finished ✅');
});

it('posts a message from a Slack-compatible text payload', function (): void {
    [$webhook, $token] = makeWebhook();

    $this->postJson("/webhooks/incoming/{$token}", ['text' => 'Build #42 passed'])
        ->assertStatus(202);

    $this->assertDatabaseHas('messages', [
        'channel_id' => $webhook->channel_id,
        'body' => 'Build #42 passed',
    ]);
});

it('prefers the native body over the Slack text when both are present', function (): void {
    [$webhook, $token] = makeWebhook();

    $this->postJson("/webhooks/incoming/{$token}", ['body' => 'native', 'text' => 'slack'])
        ->assertStatus(202);

    $this->assertDatabaseHas('messages', ['channel_id' => $webhook->channel_id, 'body' => 'native']);
});

it('404s an unknown token', function (): void {
    makeWebhook();

    $this->postJson('/webhooks/incoming/'.Str::random(48), ['body' => 'hi'])
        ->assertNotFound();

    $this->assertDatabaseCount('messages', 0);
});

it('404s a revoked token', function (): void {
    [, $token] = makeWebhook(['revoked_at' => now()]);

    $this->postJson("/webhooks/incoming/{$token}", ['body' => 'hi'])
        ->assertNotFound();
});

it('404s when the integrations platform is disabled', function (): void {
    config(['integrations.enabled' => false]);
    [, $token] = makeWebhook();

    $this->postJson("/webhooks/incoming/{$token}", ['body' => 'hi'])
        ->assertNotFound();
});

it('422s an empty payload with no body or text', function (): void {
    [, $token] = makeWebhook();

    $this->postJson("/webhooks/incoming/{$token}", ['blocks' => [['type' => 'section']]])
        ->assertStatus(422);
});

it('422s a body that exceeds the maximum length', function (): void {
    [, $token] = makeWebhook();

    $this->postJson("/webhooks/incoming/{$token}", ['body' => str_repeat('a', 8001)])
        ->assertStatus(422);

    $this->assertDatabaseCount('messages', 0);
});

it('accepts a correctly HMAC-signed request when the webhook requires signing', function (): void {
    $secret = Str::random(48);
    [$webhook, $token] = makeWebhook(['signing_secret' => $secret]);

    $payload = ['body' => 'signed and sealed'];
    $signature = hash_hmac('sha256', json_encode($payload), $secret);

    $this->postJson("/webhooks/incoming/{$token}", $payload, ['X-Signature-256' => 'sha256='.$signature])
        ->assertStatus(202);

    $this->assertDatabaseHas('messages', ['channel_id' => $webhook->channel_id, 'body' => 'signed and sealed']);
});

it('accepts a bare hex signature without the sha256= prefix', function (): void {
    $secret = Str::random(48);
    [$webhook, $token] = makeWebhook(['signing_secret' => $secret]);

    $payload = ['body' => 'bare hex'];
    $signature = hash_hmac('sha256', json_encode($payload), $secret);

    $this->postJson("/webhooks/incoming/{$token}", $payload, ['X-Signature-256' => $signature])
        ->assertStatus(202);

    $this->assertDatabaseHas('messages', ['channel_id' => $webhook->channel_id, 'body' => 'bare hex']);
});

it('401s a signed webhook when the signature header is missing', function (): void {
    [, $token] = makeWebhook(['signing_secret' => Str::random(48)]);

    $this->postJson("/webhooks/incoming/{$token}", ['body' => 'unsigned'])
        ->assertStatus(401);
});

it('401s a signed webhook when the signature does not match', function (): void {
    [, $token] = makeWebhook(['signing_secret' => Str::random(48)]);

    $this->postJson("/webhooks/incoming/{$token}", ['body' => 'tampered'], ['X-Signature-256' => 'sha256=deadbeef'])
        ->assertStatus(401);
});

it('403s when the bound channel has been archived', function (): void {
    [$webhook, $token] = makeWebhook();
    $webhook->channel->update(['archived_at' => now()]);

    $this->postJson("/webhooks/incoming/{$token}", ['body' => 'too late'])
        ->assertStatus(403);
});

it('403s when the bot is no longer a member of the channel', function (): void {
    [$webhook, $token] = makeWebhook();
    $webhook->channel->channelMembers()->where('user_id', $webhook->bot_id)->delete();

    $this->postJson("/webhooks/incoming/{$token}", ['body' => 'orphaned'])
        ->assertStatus(403);
});

it('throttles each webhook token independently, not by shared IP', function (): void {
    config(['integrations.api_rate_limit' => 1]);
    [, $tokenA] = makeWebhook();
    [, $tokenB] = makeWebhook();

    $this->postJson("/webhooks/incoming/{$tokenA}", ['body' => 'first'])->assertStatus(202);
    $this->postJson("/webhooks/incoming/{$tokenA}", ['body' => 'second'])->assertStatus(429);

    // A different token has its own quota despite the same client IP.
    $this->postJson("/webhooks/incoming/{$tokenB}", ['body' => 'other'])->assertStatus(202);
});
