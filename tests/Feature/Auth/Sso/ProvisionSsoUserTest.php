<?php

use App\Actions\Sso\ProvisionSsoUser;
use App\Enums\SecurityEventType;
use App\Enums\TeamRole;
use App\Exceptions\Sso\UnverifiedSsoEmailException;
use App\Models\Channel;
use App\Models\SecurityEvent;
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

test('a just-in-time provisioned user records an account provisioned security event', function (): void {
    Team::factory()->create();

    $user = app(ProvisionSsoUser::class)->handle('oidc', 'sub-123', 'jordan@example.com', 'Jordan Rivers');

    expect(SecurityEvent::query()->where('user_id', $user->id)->where('type', SecurityEventType::AccountProvisioned)->count())->toBe(1);
});

test('an existing user with a matching email is linked, not duplicated', function (): void {
    $existing = User::factory()->create(['email' => 'jordan@example.com']);

    $user = app(ProvisionSsoUser::class)->handle('oidc', 'sub-123', 'Jordan@Example.com', 'Jordan Rivers');

    expect($user->id)->toBe($existing->id)
        ->and(User::query()->where('email', 'jordan@example.com')->count())->toBe(1);

    expect($existing->fresh()->ssoIdentities()->where('provider_id', 'sub-123')->exists())->toBeTrue();

    expect(SecurityEvent::query()->where('type', SecurityEventType::AccountProvisioned)->count())->toBe(0);
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

test('syncName refreshes the display name of an email-matched user from the directory', function (): void {
    $existing = User::factory()->create(['email' => 'jordan@example.com', 'name' => 'Old Name']);

    $user = app(ProvisionSsoUser::class)->handle('ldap', 'guid-1', 'jordan@example.com', 'Jordan Rivers', syncName: true);

    expect($user->id)->toBe($existing->id)
        ->and($existing->fresh()->name)->toBe('Jordan Rivers');
});

test('syncName refreshes the display name of an identity-matched user on a return login', function (): void {
    $existing = User::factory()->create(['name' => 'Old Name']);
    SsoIdentity::factory()->provider('ldap')->for($existing)->create(['provider_id' => 'guid-1']);

    app(ProvisionSsoUser::class)->handle('ldap', 'guid-1', 'someone@example.com', 'Jordan Rivers', syncName: true);

    expect($existing->fresh()->name)->toBe('Jordan Rivers');
});

test('syncName leaves the name untouched when the directory supplies no name', function (): void {
    $existing = User::factory()->create(['name' => 'Kept Name']);
    SsoIdentity::factory()->provider('ldap')->for($existing)->create(['provider_id' => 'guid-1']);

    app(ProvisionSsoUser::class)->handle('ldap', 'guid-1', 'someone@example.com', null, syncName: true);

    expect($existing->fresh()->name)->toBe('Kept Name');
});

test('without syncName an existing matched user keeps their current name', function (): void {
    $existing = User::factory()->create(['email' => 'jordan@example.com', 'name' => 'Kept Name']);

    app(ProvisionSsoUser::class)->handle('oidc', 'sub-123', 'jordan@example.com', 'Jordan Rivers');

    expect($existing->fresh()->name)->toBe('Kept Name');
});

test('an unverified email is rejected instead of linking to the existing account', function (): void {
    User::factory()->create(['email' => 'jordan@example.com']);

    expect(fn (): User => app(ProvisionSsoUser::class)->handle('oidc', 'sub-123', 'jordan@example.com', 'Jordan Rivers', emailVerified: false))
        ->toThrow(UnverifiedSsoEmailException::class);

    expect(SsoIdentity::query()->count())->toBe(0)
        ->and(User::query()->count())->toBe(1);
});

test('an unverified email is rejected instead of just-in-time provisioning a new account', function (): void {
    Team::factory()->create();

    expect(fn (): User => app(ProvisionSsoUser::class)->handle('oidc', 'sub-123', 'jordan@example.com', 'Jordan Rivers', emailVerified: false))
        ->toThrow(UnverifiedSsoEmailException::class);

    expect(User::query()->count())->toBe(0)
        ->and(SsoIdentity::query()->count())->toBe(0);
});

test('an already-linked identity still resolves when the directory reports the email unverified', function (): void {
    $existing = User::factory()->create();
    SsoIdentity::factory()->provider('oidc')->for($existing)->create(['provider_id' => 'sub-xyz']);

    $user = app(ProvisionSsoUser::class)->handle('oidc', 'sub-xyz', 'someone-else@example.com', 'Someone Else', emailVerified: false);

    expect($user->id)->toBe($existing->id);
});
