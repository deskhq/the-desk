<?php

use App\Enums\SecurityEventType;
use App\Enums\TeamRole;
use App\Http\Controllers\Scim\ScimUserController;
use App\Models\SecurityEvent;
use App\Models\Team;
use App\Models\User;
use App\Scim\ScimConfig;
use App\Support\SessionRegistry;
use ArieTimmerman\Laravel\SCIMServer\Http\Controllers\ResourceController;
use ArieTimmerman\Laravel\SCIMServer\SCIMConfig as PackageScimConfig;

/**
 * Reboot the app with SCIM provisioning enabled, so the routes are mounted and
 * the bearer guard has a token to check against.
 */
function enableScim(): void
{
    test()->reloadWithEnv(['SCIM_TOKEN' => SCIM_TEST_TOKEN]);
}

beforeEach(function (): void {
    enableScim();
});

// --- Provider wiring & endpoint gating -------------------------------------

test('the SCIM server resolves to this app\'s controller and resource map', function (): void {
    expect(app(ResourceController::class))->toBeInstanceOf(ScimUserController::class)
        ->and(app(PackageScimConfig::class))->toBeInstanceOf(ScimConfig::class);
});

test('only the Users resource is exposed; Groups are out of scope', function (): void {
    expect(array_keys(app(ScimConfig::class)->getConfig()))->toBe(['Users']);

    $this->withToken(SCIM_TEST_TOKEN)
        ->postJson('/scim/v2/Groups', ['schemas' => ['urn:ietf:params:scim:schemas:core:2.0:Group']])
        ->assertNotFound();
});

test('the SCIM endpoints do not exist when no token is configured', function (): void {
    test()->reloadWithEnv(['SCIM_TOKEN' => '']);

    $this->postJson('/scim/v2/Users', scimUserPayload())->assertNotFound();
});

// --- Bearer-token auth ------------------------------------------------------

test('a request without a bearer token is rejected with a SCIM error', function (): void {
    $response = $this->postJson('/scim/v2/Users', scimUserPayload())
        ->assertUnauthorized()
        ->assertJsonPath('schemas.0', 'urn:ietf:params:scim:api:messages:2.0:Error')
        ->assertJsonPath('status', '401');

    expect($response->headers->get('Content-Type'))->toContain('application/scim+json')
        ->and($response->headers->get('WWW-Authenticate'))->toBe('Bearer');
});

test('a request with the wrong bearer token is rejected', function (): void {
    $this->withToken('nope')
        ->postJson('/scim/v2/Users', scimUserPayload())
        ->assertUnauthorized();
});

test('the discovery endpoints are also guarded by the bearer token', function (): void {
    $this->getJson('/scim/v2/ServiceProviderConfig')->assertUnauthorized();

    $this->withToken(SCIM_TEST_TOKEN)
        ->getJson('/scim/v2/ServiceProviderConfig')
        ->assertOk();
});

// --- Create (JIT / matching) -----------------------------------------------

test('a create just-in-time provisions the user into the default team as a member', function (): void {
    $team = Team::factory()->create();

    $response = $this->withToken(SCIM_TEST_TOKEN)->postJson('/scim/v2/Users', scimUserPayload([
        'externalId' => 'okta-123',
    ]));

    $response->assertCreated()
        ->assertJsonPath('userName', 'ada@example.com')
        ->assertJsonPath('name.formatted', 'Ada Byte')
        ->assertJsonPath('active', true);

    $user = User::query()->whereRaw('lower(email) = ?', ['ada@example.com'])->firstOrFail();

    expect($user->teamRole($team))->toBe(TeamRole::Member)
        ->and($user->hasVerifiedEmail())->toBeTrue();

    $this->assertDatabaseHas('sso_identities', [
        'provider' => 'scim',
        'provider_id' => 'okta-123',
        'user_id' => $user->id,
    ]);
});

test('a create for an existing email links that account instead of duplicating it', function (): void {
    $existing = User::factory()->create(['email' => 'ada@example.com']);

    $this->withToken(SCIM_TEST_TOKEN)
        ->postJson('/scim/v2/Users', scimUserPayload(['externalId' => 'okta-123']))
        ->assertCreated();

    expect(User::query()->where('email', 'ada@example.com')->count())->toBe(1);

    $this->assertDatabaseHas('sso_identities', [
        'provider' => 'scim',
        'provider_id' => 'okta-123',
        'user_id' => $existing->id,
    ]);
});

test('a create falls back to the first email when no userName is sent', function (): void {
    $this->withToken(SCIM_TEST_TOKEN)->postJson('/scim/v2/Users', [
        'schemas' => [SCIM_USER_SCHEMA],
        'emails' => [['value' => 'grace@example.com', 'primary' => true]],
        'name' => ['formatted' => 'Grace Hopper'],
    ])->assertCreated()->assertJsonPath('userName', 'grace@example.com');

    $this->assertDatabaseHas('users', ['email' => 'grace@example.com']);
});

test('a create without any identifier is rejected', function (): void {
    $this->withToken(SCIM_TEST_TOKEN)->postJson('/scim/v2/Users', [
        'schemas' => [SCIM_USER_SCHEMA],
        'name' => ['formatted' => 'Nobody'],
    ])->assertStatus(400);
});

test('a create can provision an already-deactivated user', function (): void {
    $this->withToken(SCIM_TEST_TOKEN)
        ->postJson('/scim/v2/Users', scimUserPayload(['active' => false, 'externalId' => 'okta-9']))
        ->assertCreated()
        ->assertJsonPath('active', false);

    $user = User::query()->whereRaw('lower(email) = ?', ['ada@example.com'])->firstOrFail();

    expect($user->isDeactivated())->toBeTrue();
});

// --- Read & list-with-filter ------------------------------------------------

test('a user can be read back by id', function (): void {
    $user = User::factory()->create(['email' => 'ada@example.com', 'name' => 'Ada Byte']);

    $this->withToken(SCIM_TEST_TOKEN)
        ->getJson("/scim/v2/Users/{$user->id}")
        ->assertOk()
        ->assertJsonPath('id', $user->id)
        ->assertJsonPath('userName', 'ada@example.com')
        ->assertJsonPath('active', true);
});

test('users can be looked up by a userName filter', function (): void {
    User::factory()->create(['email' => 'ada@example.com']);
    User::factory()->create(['email' => 'grace@example.com']);

    $response = $this->withToken(SCIM_TEST_TOKEN)
        ->getJson('/scim/v2/Users?filter='.urlencode('userName eq "ada@example.com"'))
        ->assertOk()
        ->assertJsonPath('totalResults', 1);

    expect($response->json('Resources.0.userName'))->toBe('ada@example.com');
});

test('users can be filtered by active state', function (): void {
    User::factory()->create(['email' => 'active@example.com']);
    User::factory()->create(['email' => 'gone@example.com', 'deactivated_at' => now()]);

    $this->withToken(SCIM_TEST_TOKEN)
        ->getJson('/scim/v2/Users?filter='.urlencode('active eq true'))
        ->assertOk()
        ->assertJsonPath('totalResults', 1)
        ->assertJsonPath('Resources.0.userName', 'active@example.com');

    $this->withToken(SCIM_TEST_TOKEN)
        ->getJson('/scim/v2/Users?filter='.urlencode('active eq false'))
        ->assertOk()
        ->assertJsonPath('totalResults', 1)
        ->assertJsonPath('Resources.0.userName', 'gone@example.com');
});

// --- Attribute update -------------------------------------------------------

test('a patch syncs the display name', function (): void {
    $user = User::factory()->create(['email' => 'ada@example.com', 'name' => 'Ada Byte']);

    $this->withToken(SCIM_TEST_TOKEN)
        ->patchJson("/scim/v2/Users/{$user->id}", scimPatch([
            ['op' => 'replace', 'path' => 'name.formatted', 'value' => 'Ada Lovelace'],
        ]))
        ->assertOk()
        ->assertJsonPath('name.formatted', 'Ada Lovelace');

    expect($user->fresh()->name)->toBe('Ada Lovelace');
});

test('a put replaces mapped profile fields', function (): void {
    $user = User::factory()->create(['email' => 'ada@example.com', 'name' => 'Ada Byte']);

    $this->withToken(SCIM_TEST_TOKEN)
        ->putJson("/scim/v2/Users/{$user->id}", scimUserPayload([
            'userName' => 'ada@example.com',
            'name' => ['formatted' => 'Ada Renamed'],
        ]))
        ->assertOk()
        ->assertJsonPath('name.formatted', 'Ada Renamed');

    expect($user->fresh()->name)->toBe('Ada Renamed');
});

// --- Deactivate / reactivate ------------------------------------------------

test('a delete tombstones the user and revokes their sessions instead of hard-deleting', function (): void {
    $user = User::factory()->create(['email' => 'ada@example.com']);
    app(SessionRegistry::class)->record($user->id, 'session-a', '203.0.113.1', 'Chrome', now()->timestamp);

    $this->withToken(SCIM_TEST_TOKEN)
        ->deleteJson("/scim/v2/Users/{$user->id}")
        ->assertNoContent();

    expect($user->fresh()->isDeactivated())->toBeTrue()
        ->and(app(SessionRegistry::class)->all($user->id))->toBe([]);
});

test('a patch with active false deactivates and revokes sessions', function (): void {
    $user = User::factory()->create(['email' => 'ada@example.com']);
    app(SessionRegistry::class)->record($user->id, 'session-a', '203.0.113.1', 'Chrome', now()->timestamp);

    $this->withToken(SCIM_TEST_TOKEN)
        ->patchJson("/scim/v2/Users/{$user->id}", scimPatch([
            ['op' => 'replace', 'path' => 'active', 'value' => false],
        ]))
        ->assertOk()
        ->assertJsonPath('active', false);

    expect($user->fresh()->isDeactivated())->toBeTrue()
        ->and(app(SessionRegistry::class)->all($user->id))->toBe([]);
});

test('a patch with active true reactivates a deactivated user', function (): void {
    $user = User::factory()->create(['email' => 'ada@example.com', 'deactivated_at' => now()]);

    $this->withToken(SCIM_TEST_TOKEN)
        ->patchJson("/scim/v2/Users/{$user->id}", scimPatch([
            ['op' => 'replace', 'path' => 'active', 'value' => true],
        ]))
        ->assertOk()
        ->assertJsonPath('active', true);

    expect($user->fresh()->isDeactivated())->toBeFalse();
});

test('a patch that deactivates records an account deactivated security event', function (): void {
    $user = User::factory()->create(['email' => 'ada@example.com']);

    $this->withToken(SCIM_TEST_TOKEN)
        ->patchJson("/scim/v2/Users/{$user->id}", scimPatch([
            ['op' => 'replace', 'path' => 'active', 'value' => false],
        ]))
        ->assertOk();

    expect(SecurityEvent::query()->where('user_id', $user->id)->where('type', SecurityEventType::AccountDeactivated)->count())->toBe(1);
});

test('a delete records an account deactivated security event', function (): void {
    $user = User::factory()->create(['email' => 'ada@example.com']);

    $this->withToken(SCIM_TEST_TOKEN)
        ->deleteJson("/scim/v2/Users/{$user->id}")
        ->assertNoContent();

    expect(SecurityEvent::query()->where('user_id', $user->id)->where('type', SecurityEventType::AccountDeactivated)->count())->toBe(1);
});
