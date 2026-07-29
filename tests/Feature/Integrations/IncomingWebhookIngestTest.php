<?php

declare(strict_types=1);

use App\Events\MessageSent;
use App\Models\Channel;
use App\Models\IncomingWebhook;
use App\Models\Message;
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

it('snapshots the requested display identity onto the message', function (): void {
    [$webhook, $token] = makeWebhook();

    $this->postJson("/webhooks/incoming/{$token}", [
        'text' => 'Rolled out to production',
        'username' => 'Release Train',
        'icon_url' => 'https://cdn.example.test/train.png',
    ])->assertStatus(202);

    $this->assertDatabaseHas('messages', [
        'channel_id' => $webhook->channel_id,
        // The bot still authors the row: the override changes what is displayed,
        // never who posted.
        'user_id' => $webhook->bot_id,
        'author_override_name' => 'Release Train',
        'author_override_avatar_url' => 'https://cdn.example.test/train.png',
    ]);
});

it('broadcasts the override beside a truthful, still-flagged bot author', function (): void {
    Event::fake([MessageSent::class]);
    [, $token] = makeWebhook();

    $this->postJson("/webhooks/incoming/{$token}", [
        'text' => 'Rolled out',
        'username' => 'Release Train',
        'icon_url' => 'https://cdn.example.test/train.png',
    ])->assertStatus(202);

    Event::assertDispatched(MessageSent::class, function (MessageSent $event): bool {
        expect($event->message->user->name)->toBe('Deploy Bot')
            ->and($event->message->user->isBot)->toBeTrue()
            ->and($event->message->authorOverride?->name)->toBe('Release Train')
            // The icon is proxied, so no reader's IP reaches the icon's host.
            ->and($event->message->authorOverride?->avatar)->toStartWith('/images/proxy');

        return true;
    });
});

it('accepts an override that names only the icon, keeping the bot name', function (): void {
    [$webhook, $token] = makeWebhook();

    $this->postJson("/webhooks/incoming/{$token}", [
        'text' => 'Icon only',
        'icon_url' => 'https://cdn.example.test/train.png',
    ])->assertStatus(202);

    $this->assertDatabaseHas('messages', [
        'channel_id' => $webhook->channel_id,
        'author_override_name' => null,
        'author_override_avatar_url' => 'https://cdn.example.test/train.png',
    ]);
});

it('accepts a plain http icon url', function (): void {
    [$webhook, $token] = makeWebhook();

    $this->postJson("/webhooks/incoming/{$token}", [
        'text' => 'Self-hosted asset host',
        'icon_url' => 'http://assets.internal/bot.png',
    ])->assertStatus(202);

    $this->assertDatabaseHas('messages', [
        'channel_id' => $webhook->channel_id,
        'author_override_avatar_url' => 'http://assets.internal/bot.png',
    ]);
});

it('treats a blank override field as absent and posts under the bot identity', function (): void {
    [$webhook, $token] = makeWebhook();

    $this->postJson("/webhooks/incoming/{$token}", [
        'text' => 'Unset template variable',
        'username' => '',
        'icon_url' => '   ',
    ])->assertStatus(202);

    $this->assertDatabaseHas('messages', [
        'channel_id' => $webhook->channel_id,
        'body' => 'Unset template variable',
        'author_override_name' => null,
        'author_override_avatar_url' => null,
    ]);
});

it('ignores the Slack icon_emoji field', function (): void {
    [$webhook, $token] = makeWebhook();

    $this->postJson("/webhooks/incoming/{$token}", [
        'text' => 'Emoji icons are not resolved',
        'icon_emoji' => ':rocket:',
    ])->assertStatus(202);

    $this->assertDatabaseHas('messages', [
        'channel_id' => $webhook->channel_id,
        'author_override_name' => null,
        'author_override_avatar_url' => null,
    ]);
});

it('422s a malformed identity override rather than posting under the wrong name', function (array $payload): void {
    [, $token] = makeWebhook();

    $this->postJson("/webhooks/incoming/{$token}", ['text' => 'hi', ...$payload])
        ->assertStatus(422);

    $this->assertDatabaseCount('messages', 0);
})->with([
    'an over-long username' => [['username' => str_repeat('a', 256)]],
    'a non-string username' => [['username' => 42]],
    'an over-long icon url' => [['icon_url' => 'https://cdn.example.test/'.str_repeat('a', 2048)]],
    'a non-http icon url' => [['icon_url' => 'ftp://cdn.example.test/train.png']],
    'a javascript icon url' => [['icon_url' => 'javascript:alert(1)']],
    'a non-string icon url' => [['icon_url' => ['nested']]],
]);

it('answers a sender that never set an Accept header with a machine-readable 422', function (): void {
    [, $token] = makeWebhook();

    // What a plain `curl -H 'Content-Type: application/json'` sends: without the
    // guard, Laravel would redirect and the sender would read a 302 as success.
    $this->call(
        'POST',
        "/webhooks/incoming/{$token}",
        server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => '*/*'],
        content: json_encode(['text' => 'hi', 'icon_url' => 'ftp://cdn.example.test/train.png'], JSON_THROW_ON_ERROR),
    )
        ->assertStatus(422)
        ->assertJsonValidationErrors('icon_url');

    $this->assertDatabaseCount('messages', 0);
});

it('keeps the snapshot after the webhook is revoked and its bot renamed', function (): void {
    [$webhook, $token] = makeWebhook();

    $this->postJson("/webhooks/incoming/{$token}", ['text' => 'Shipped', 'username' => 'Release Train'])
        ->assertStatus(202);

    $webhook->bot->update(['name' => 'Retired Bot']);
    $webhook->update(['revoked_at' => now()]);

    $message = Message::query()->where('channel_id', $webhook->channel_id)->sole();

    expect($message->author_override_name)->toBe('Release Train');
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
