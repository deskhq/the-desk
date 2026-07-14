<?php

use App\Enums\TeamRole;
use App\Ldap\DirectoryUser;
use App\Models\SsoIdentity;
use App\Models\Team;
use App\Models\User;
use App\Services\Sso\LdapAuthenticator;
use LdapRecord\Laravel\Testing\DirectoryEmulator;

afterEach(function (): void {
    DirectoryEmulator::tearDown();
});

test('a directory user is bound, just-in-time provisioned into the sole team, and logged in', function (): void {
    $this->reloadWithEnv(ldapEnv());
    $team = Team::factory()->create();

    $fake = fakeDirectory();
    $entry = directoryUser(['cn' => 'Ada Byte', 'mail' => 'ada@example.com', 'uid' => 'ada']);
    $fake->actingAs($entry);

    $response = $this->post('/login', ['email' => 'ada@example.com', 'password' => 'directory-secret']);

    $this->assertAuthenticated();
    $response->assertRedirect();

    $user = auth()->user();
    expect($user->email)->toBe('ada@example.com')
        ->and($user->name)->toBe('Ada Byte')
        ->and($user->password)->toBeNull()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->teamRole($team))->toBe(TeamRole::Member);

    $this->assertDatabaseHas('sso_identities', [
        'provider' => 'ldap',
        'user_id' => $user->id,
    ]);
});

test('a directory login for an existing email links that account and syncs the display name', function (): void {
    $this->reloadWithEnv(ldapEnv());
    $existing = User::factory()->create(['email' => 'ada@example.com', 'name' => 'Old Name']);

    $fake = fakeDirectory();
    $entry = directoryUser(['cn' => 'Ada Byte', 'mail' => 'ada@example.com', 'uid' => 'ada']);
    $fake->actingAs($entry);

    $this->post('/login', ['email' => 'ada@example.com', 'password' => 'directory-secret']);

    expect(auth()->id())->toBe($existing->id)
        ->and($existing->fresh()->name)->toBe('Ada Byte')
        ->and(User::query()->where('email', 'ada@example.com')->count())->toBe(1);

    $this->assertDatabaseHas('sso_identities', [
        'provider' => 'ldap',
        'user_id' => $existing->id,
    ]);
});

test('a rejected bind (wrong password) fails the login and leaves the user a guest', function (): void {
    $this->reloadWithEnv(ldapEnv());

    // The entry exists but is never `actingAs`, so any bind attempt is rejected.
    fakeDirectory();
    directoryUser(['cn' => 'Ada Byte', 'mail' => 'ada@example.com', 'uid' => 'ada']);

    $response = $this->post('/login', ['email' => 'ada@example.com', 'password' => 'wrong']);

    $this->assertGuest();
    $response->assertSessionHasErrors();
    expect(User::query()->count())->toBe(0);
});

test('the authenticator refuses blank credentials without ever binding, guarding against anonymous binds', function (): void {
    // A blank password must be rejected before any bind: LDAP servers can treat
    // an empty password as an anonymous bind and return success, which would let
    // a known username in with no password.
    $authenticator = app(LdapAuthenticator::class);

    expect($authenticator->attempt('', 'secret'))->toBeNull()
        ->and($authenticator->attempt('ada@example.com', ''))->toBeNull();
});

test('an unknown directory user fails the login', function (): void {
    $this->reloadWithEnv(ldapEnv());
    fakeDirectory();

    $response = $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'secret']);

    $this->assertGuest();
    $response->assertSessionHasErrors();
    expect(User::query()->count())->toBe(0);
});

test('a bound entry with no mail attribute is a clear failure and provisions nobody', function (): void {
    // Sign in with a directory username (uid) rather than email, so the entry can
    // be found and bound yet still lack the mail attribute we match the app user on.
    $this->reloadWithEnv(ldapEnv(['LDAP_ATTR_USERNAME' => 'uid']));

    $fake = fakeDirectory();
    $entry = directoryUser(['cn' => 'No Mail', 'uid' => 'nomail']);
    $fake->actingAs($entry);

    $response = $this->post('/login', ['email' => 'nomail', 'password' => 'directory-secret']);

    $this->assertGuest();
    $response->assertSessionHasErrors();
    expect(User::query()->count())->toBe(0);
});

test('an unreachable directory fails gracefully rather than erroring', function (): void {
    $this->reloadWithEnv(ldapEnv());
    fakeDirectory();

    // Point the authenticator at a connection that was never registered, so the
    // lookup throws inside the guard and the login fails cleanly.
    config(['sso.ldap.connection' => 'unconfigured']);

    $response = $this->post('/login', ['email' => 'ada@example.com', 'password' => 'directory-secret']);

    $this->assertGuest();
    $response->assertSessionHasErrors();
});

test('under sso enforcement a local password login is rejected but the login POST is not blocked', function (): void {
    $this->reloadWithEnv(ldapEnv(['AUTH_SSO_ONLY' => true]));
    fakeDirectory();
    $user = User::factory()->create(['password' => Hash::make('password')]);

    $response = $this->post('/login', ['email' => $user->email, 'password' => 'password']);

    // The middleware lets the POST through (not a 404) so LDAP can bind, but the
    // custom callback refuses to fall back to the local password under enforcement.
    $this->assertGuest();
    $response->assertSessionHasErrors();
    $response->assertStatus(302);
});

test('under sso enforcement a directory bind still logs in', function (): void {
    $this->reloadWithEnv(ldapEnv(['AUTH_SSO_ONLY' => true]));
    Team::factory()->create();

    $fake = fakeDirectory();
    $entry = directoryUser(['cn' => 'Ada Byte', 'mail' => 'ada@example.com', 'uid' => 'ada']);
    $fake->actingAs($entry);

    $this->post('/login', ['email' => 'ada@example.com', 'password' => 'directory-secret']);

    $this->assertAuthenticated();
    expect(auth()->user()->email)->toBe('ada@example.com');
});

test('when LDAP is enabled but not enforced a local break-glass password still works', function (): void {
    $this->reloadWithEnv(ldapEnv());
    fakeDirectory();
    $user = User::factory()->create(['password' => Hash::make('password')]);

    $this->post('/login', ['email' => $user->email, 'password' => 'password']);

    $this->assertAuthenticated();
    expect(auth()->id())->toBe($user->id);
});

test('the configurable GUID attribute keys the identity, e.g. entryuuid for OpenLDAP', function (): void {
    // Point the identity at OpenLDAP's entryuuid instead of AD's objectguid and
    // confirm that exact value — not an auto-assigned objectguid — becomes the
    // stored provider id, proving the mapping is honoured.
    $this->reloadWithEnv(ldapEnv(['LDAP_ATTR_GUID' => 'entryuuid']));
    Team::factory()->create();

    $fake = fakeDirectory();
    $entry = directoryUser([
        'cn' => 'Ada Byte',
        'mail' => 'ada@example.com',
        'uid' => 'ada',
        'entryuuid' => '11111111-2222-3333-4444-555555555555',
    ]);
    $fake->actingAs($entry);

    $this->post('/login', ['email' => 'ada@example.com', 'password' => 'directory-secret']);

    $this->assertAuthenticated();

    $guid = DirectoryUser::query()->where('mail', '=', 'ada@example.com')->first()->getConvertedGuid();
    expect($guid)->toBe('11111111-2222-3333-4444-555555555555');
    $this->assertDatabaseHas('sso_identities', [
        'provider' => 'ldap',
        'provider_id' => $guid,
    ]);
});

test('a returning directory user resolves to the same account without duplicating the identity', function (): void {
    $this->reloadWithEnv(ldapEnv());
    Team::factory()->create();

    $fake = fakeDirectory();
    $entry = directoryUser(['cn' => 'Ada Byte', 'mail' => 'ada@example.com', 'uid' => 'ada']);
    $fake->actingAs($entry);

    $this->post('/login', ['email' => 'ada@example.com', 'password' => 'directory-secret']);
    $firstId = auth()->id();
    auth()->logout();

    $this->post('/login', ['email' => 'ada@example.com', 'password' => 'directory-secret']);

    expect(auth()->id())->toBe($firstId)
        ->and(User::query()->count())->toBe(1)
        ->and(SsoIdentity::query()->where('provider', 'ldap')->count())->toBe(1);
});
