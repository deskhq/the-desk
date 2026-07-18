<?php

declare(strict_types=1);

use App\Actions\Integrations\MintPersonalAccessToken;
use App\Enums\AuditAction;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;

beforeEach(function (): void {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user, ['role' => TeamRole::Member->value]);
    $this->actingAs($this->user);
});

it('lists only the acting user’s personal access tokens', function (): void {
    $mine = app(MintPersonalAccessToken::class)
        ->handle($this->user, $this->team, 'Mine', ['channels:read']);

    $stranger = User::factory()->create();
    $strangerTeam = Team::factory()->create();
    $strangerTeam->members()->attach($stranger, ['role' => TeamRole::Member->value]);
    app(MintPersonalAccessToken::class)
        ->handle($stranger, $strangerTeam, 'Theirs', ['channels:read']);

    $this->getJson(route('personal-access-tokens.index'))
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Mine')
        ->assertJsonPath('data.0.team.id', $this->team->id)
        ->assertJsonCount(1, 'data')
        ->assertJsonMissing(['name' => 'Theirs']);

    expect($mine->accessToken->team_id)->toBe($this->team->id);
});

it('mints a token bound to a team the user belongs to and returns the plaintext once', function (): void {
    $response = $this->postJson(route('personal-access-tokens.store'), [
        'name' => 'CI deploy',
        'team_id' => $this->team->id,
        'abilities' => ['channels:read', 'messages:write'],
    ])->assertCreated();

    $plain = $response->json('token');
    expect($plain)->toBeString()->not->toBeEmpty();

    $this->assertDatabaseHas('personal_access_tokens', [
        'tokenable_id' => $this->user->id,
        'name' => 'CI deploy',
        'team_id' => $this->team->id,
    ]);

    $this->assertDatabaseHas('activity_log', [
        'event' => AuditAction::PersonalAccessTokenCreated->value,
        'team_id' => $this->team->id,
    ]);
});

it('rejects a token bound to a team the user does not belong to', function (): void {
    $foreignTeam = Team::factory()->create();

    $this->postJson(route('personal-access-tokens.store'), [
        'name' => 'CI',
        'team_id' => $foreignTeam->id,
        'abilities' => ['channels:read'],
    ])->assertStatus(422)->assertJsonValidationErrorFor('team_id');
});

it('rejects an unknown ability', function (): void {
    $this->postJson(route('personal-access-tokens.store'), [
        'name' => 'CI',
        'team_id' => $this->team->id,
        'abilities' => ['channels:read', 'nonsense:write'],
    ])->assertStatus(422)->assertJsonValidationErrorFor('abilities.1');
});

it('requires a name and at least one ability', function (): void {
    $this->postJson(route('personal-access-tokens.store'), [
        'team_id' => $this->team->id,
        'abilities' => [],
    ])->assertStatus(422)
        ->assertJsonValidationErrorFor('name')
        ->assertJsonValidationErrorFor('abilities');
});

it('revokes the acting user’s own token', function (): void {
    $token = app(MintPersonalAccessToken::class)
        ->handle($this->user, $this->team, 'CI', ['channels:read'])->accessToken;

    $this->deleteJson(route('personal-access-tokens.destroy', $token->id))
        ->assertNoContent();

    $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->id]);

    $this->assertDatabaseHas('activity_log', [
        'event' => AuditAction::PersonalAccessTokenRevoked->value,
        'team_id' => $this->team->id,
    ]);
});

it('cannot revoke another user’s token', function (): void {
    $stranger = User::factory()->create();
    $strangerTeam = Team::factory()->create();
    $strangerTeam->members()->attach($stranger, ['role' => TeamRole::Member->value]);
    $token = app(MintPersonalAccessToken::class)
        ->handle($stranger, $strangerTeam, 'Theirs', ['channels:read'])->accessToken;

    $this->deleteJson(route('personal-access-tokens.destroy', $token->id))
        ->assertNotFound();

    $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->id]);
});

it('404s the whole settings surface when integrations are disabled', function (): void {
    config(['integrations.enabled' => false]);

    $this->getJson(route('personal-access-tokens.index'))->assertNotFound();
    $this->postJson(route('personal-access-tokens.store'), [
        'name' => 'CI',
        'team_id' => $this->team->id,
        'abilities' => ['channels:read'],
    ])->assertNotFound();
});

it('requires authentication', function (): void {
    Auth::logout();

    $this->getJson(route('personal-access-tokens.index'))->assertUnauthorized();
});
