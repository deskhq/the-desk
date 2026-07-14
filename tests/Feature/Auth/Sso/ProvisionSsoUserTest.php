<?php

use App\Actions\Sso\ProvisionSsoUser;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\SsoIdentity;
use App\Models\Team;
use App\Models\User;

test('a new directory user is just-in-time provisioned into the sole team as a member', function (): void {
    $team = Team::factory()->create();

    $user = app(ProvisionSsoUser::class)->handle(
        provider: 'oidc',
        providerId: 'sub-123',
        email: 'jordan@example.com',
        name: 'Jordan Rivers',
    );

    expect($user->name)->toBe('Jordan Rivers')
        ->and($user->email)->toBe('jordan@example.com')
        ->and($user->password)->toBeNull()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->current_team_id)->toBe($team->id)
        ->and($user->teamRole($team))->toBe(TeamRole::Member);

    expect(SsoIdentity::query()->where('provider', 'oidc')->where('provider_id', 'sub-123')->first())
        ->not->toBeNull()
        ->user_id->toBe($user->id);
});

test('an existing user with a matching email is linked, not duplicated', function (): void {
    $existing = User::factory()->create(['email' => 'jordan@example.com']);

    $user = app(ProvisionSsoUser::class)->handle('oidc', 'sub-123', 'Jordan@Example.com', 'Jordan Rivers');

    expect($user->id)->toBe($existing->id)
        ->and(User::query()->where('email', 'jordan@example.com')->count())->toBe(1);

    expect($existing->fresh()->ssoIdentities()->where('provider_id', 'sub-123')->exists())->toBeTrue();
});

test('an already-linked identity resolves straight to its user, even against a different email', function (): void {
    $existing = User::factory()->create();
    SsoIdentity::factory()->provider('oidc')->for($existing)->create(['provider_id' => 'sub-xyz']);

    $user = app(ProvisionSsoUser::class)->handle('oidc', 'sub-xyz', 'someone-else@example.com', 'Someone Else');

    expect($user->id)->toBe($existing->id)
        ->and(User::query()->count())->toBe(1)
        ->and(SsoIdentity::query()->count())->toBe(1);
});

test('a returning directory user resolves to the same account without duplicating the identity', function (): void {
    Team::factory()->create();

    $first = app(ProvisionSsoUser::class)->handle('oidc', 'sub-123', 'jordan@example.com', 'Jordan Rivers');
    $second = app(ProvisionSsoUser::class)->handle('oidc', 'sub-123', 'jordan@example.com', 'Jordan Rivers');

    expect($second->id)->toBe($first->id)
        ->and(User::query()->count())->toBe(1)
        ->and(SsoIdentity::query()->where('provider', 'oidc')->where('provider_id', 'sub-123')->count())->toBe(1);
});

test('the configured default team receives provisioned members even when several teams exist', function (): void {
    Team::factory()->create();
    $target = Team::factory()->create();
    config(['sso.default_team_id' => $target->id]);

    $user = app(ProvisionSsoUser::class)->handle('oidc', 'sub-123', 'jordan@example.com', 'Jordan Rivers');

    expect($user->current_team_id)->toBe($target->id)
        ->and($user->teamRole($target))->toBe(TeamRole::Member);
});

test('a provisioned user falls back to their own personal team when no default team resolves', function (): void {
    Team::factory()->create();
    Team::factory()->create();

    $user = app(ProvisionSsoUser::class)->handle('oidc', 'sub-123', 'jordan@example.com', 'Jordan Rivers');

    $ownTeam = $user->currentTeam;

    expect($ownTeam->is_personal)->toBeTrue()
        ->and($user->teamRole($ownTeam))->toBe(TeamRole::Owner);
});

test('a provisioned user joins the default team\'s #general channel', function (): void {
    $team = Team::factory()->create();

    $user = app(ProvisionSsoUser::class)->handle('oidc', 'sub-123', 'jordan@example.com', 'Jordan Rivers');

    $general = $team->channels()->where('slug', Channel::GENERAL_SLUG)->first();

    expect($general)->not->toBeNull()
        ->and($user->channels()->whereKey($general->id)->exists())->toBeTrue();
});

test('a provisioned user with no name falls back to their email', function (): void {
    Team::factory()->create();

    $user = app(ProvisionSsoUser::class)->handle('oidc', 'sub-123', 'jordan@example.com', null);

    expect($user->name)->toBe('jordan@example.com');
});
