<?php

declare(strict_types=1);

use App\Actions\Integrations\CreateIncomingWebhook;
use App\Actions\Integrations\RevokeIncomingWebhook;
use App\Enums\AuditAction;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\IncomingWebhook;
use App\Models\Team;
use App\Models\User;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->team = Team::factory()->create();
    $this->owner = User::factory()->create();
    $this->team->members()->attach($this->owner, ['role' => TeamRole::Owner->value]);
    $this->bot = User::factory()->bot($this->team)->create(['name' => 'Deploy Bot']);
    $this->channel = Channel::factory()->for($this->team)->create(['name' => 'ops']);
    $this->channel->channelMembers()->create(['user_id' => $this->bot->id]);
});

it('mints a webhook bound to the bot and channel, storing only the token hash, and audits it', function (): void {
    $result = app(CreateIncomingWebhook::class)->handle($this->bot, $this->channel, $this->owner, 'CI deploys');

    expect($result->token)->toBeString()->toHaveLength(48)
        ->and($result->signingSecret)->toBeNull()
        ->and($result->url())->toContain('/webhooks/incoming/'.$result->token);

    $webhook = $result->webhook->fresh();

    expect($webhook->bot_id)->toBe($this->bot->id)
        ->and($webhook->channel_id)->toBe($this->channel->id)
        ->and($webhook->team_id)->toBe($this->team->id)
        ->and($webhook->created_by)->toBe($this->owner->id)
        ->and($webhook->token_hash)->toBe(IncomingWebhook::hashToken($result->token))
        ->and($webhook->token_hash)->not->toBe($result->token);

    // The plaintext token is never persisted to the row or the audit log.
    $this->assertDatabaseMissing('incoming_webhooks', ['token_hash' => $result->token]);

    $this->assertDatabaseHas('activity_log', [
        'team_id' => $this->team->id,
        'event' => AuditAction::IncomingWebhookCreated->value,
        'causer_id' => $this->owner->id,
    ]);

    $activity = DB::table('activity_log')->where('event', AuditAction::IncomingWebhookCreated->value)->first();
    expect($activity->properties)->not->toContain($result->token);
});

it('optionally mints an encrypted HMAC signing secret', function (): void {
    $result = app(CreateIncomingWebhook::class)->handle($this->bot, $this->channel, $this->owner, 'Signed', withSigningSecret: true);

    expect($result->signingSecret)->toBeString()->toHaveLength(48)
        ->and($result->webhook->fresh()->signing_secret)->toBe($result->signingSecret);

    // The column holds ciphertext, never the plaintext secret.
    $raw = DB::table('incoming_webhooks')->where('id', $result->webhook->id)->value('signing_secret');
    expect($raw)->not->toBe($result->signingSecret);
});

it('refuses to bind a webhook to a channel the bot is not a member of', function (): void {
    $other = Channel::factory()->for($this->team)->create();

    expect(fn () => app(CreateIncomingWebhook::class)->handle($this->bot, $other, $this->owner, 'Nope'))
        ->toThrow(ValidationException::class);

    $this->assertDatabaseCount('incoming_webhooks', 0);
});

it('revokes a webhook, stamping revoked_at and auditing it', function (): void {
    $webhook = IncomingWebhook::factory()->for($this->team)->for($this->channel)
        ->for($this->bot, 'bot')->create(['name' => 'Old']);

    app(RevokeIncomingWebhook::class)->handle($this->owner, $webhook);

    expect($webhook->fresh()->revoked_at)->not->toBeNull();
    $this->assertDatabaseHas('activity_log', [
        'team_id' => $this->team->id,
        'event' => AuditAction::IncomingWebhookRevoked->value,
    ]);
});

it('is idempotent when revoking an already-revoked webhook', function (): void {
    $webhook = IncomingWebhook::factory()->for($this->team)->for($this->channel)
        ->for($this->bot, 'bot')->revoked()->create();

    app(RevokeIncomingWebhook::class)->handle($this->owner, $webhook);

    $this->assertDatabaseMissing('activity_log', [
        'event' => AuditAction::IncomingWebhookRevoked->value,
    ]);
});
