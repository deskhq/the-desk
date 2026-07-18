<?php

declare(strict_types=1);

use App\Actions\Integrations\MintBotToken;
use App\Actions\Integrations\RevokeBotToken;
use App\Enums\AuditAction;
use App\Enums\IntegrationScope;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

beforeEach(function (): void {
    $this->team = Team::factory()->create();
    $this->owner = User::factory()->create();
    $this->team->members()->attach($this->owner, ['role' => TeamRole::Owner->value]);
    $this->bot = User::factory()->bot($this->team)->create(['name' => 'Deploy Bot']);
});

it('mints a scoped token for a bot and audits it without logging the value', function (): void {
    $token = app(MintBotToken::class)->handle(
        $this->bot,
        $this->owner,
        'CI pipeline',
        [IntegrationScope::MessagesWrite->value],
    );

    expect($token->plainTextToken)->toBeString()
        ->and($token->accessToken->can(IntegrationScope::MessagesWrite->value))->toBeTrue()
        ->and($token->accessToken->can(IntegrationScope::MembersWrite->value))->toBeFalse();

    $this->assertDatabaseHas('activity_log', [
        'team_id' => $this->team->id,
        'event' => AuditAction::BotTokenCreated->value,
        'causer_id' => $this->owner->id,
    ]);

    // The token value is never persisted to the audit log.
    $activity = DB::table('activity_log')->where('event', AuditAction::BotTokenCreated->value)->first();
    expect($activity->properties)->not->toContain($token->plainTextToken);
});

it('revokes a token and audits it', function (): void {
    $token = app(MintBotToken::class)->handle($this->bot, $this->owner, 'CI', [IntegrationScope::MessagesRead->value]);
    $model = PersonalAccessToken::findOrFail($token->accessToken->getKey());

    app(RevokeBotToken::class)->handle($this->owner, $model);

    $this->assertDatabaseMissing('personal_access_tokens', ['id' => $model->getKey()]);
    $this->assertDatabaseHas('activity_log', [
        'team_id' => $this->team->id,
        'event' => AuditAction::BotTokenRevoked->value,
    ]);
});
