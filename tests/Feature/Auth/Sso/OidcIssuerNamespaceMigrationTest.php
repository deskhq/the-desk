<?php

use App\Models\SsoIdentity;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Load the issuer-namespacing data migration as an instance so its up()/down()
 * can be exercised directly against seeded rows (RefreshDatabase has already run
 * it once, on an empty table, during setup).
 */
function issuerNamespaceMigration(): object
{
    return require base_path('database/migrations/2026_07_14_170929_namespace_oidc_identities_by_issuer.php');
}

test('the migration re-keys legacy bare-oidc identities under the configured issuer', function (): void {
    config(['services.oidc.issuer' => 'https://idp.test/']);
    $user = User::factory()->create();
    DB::table('sso_identities')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'provider' => 'oidc',
        'provider_id' => 'sub-legacy',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    issuerNamespaceMigration()->up();

    $this->assertDatabaseHas('sso_identities', [
        'provider' => 'oidc:https://idp.test',
        'provider_id' => 'sub-legacy',
        'user_id' => $user->id,
    ]);
    $this->assertDatabaseMissing('sso_identities', ['provider' => 'oidc']);
});

test('the migration down() restores the bare-oidc provider key', function (): void {
    config(['services.oidc.issuer' => 'https://idp.test']);
    $user = User::factory()->create();
    SsoIdentity::factory()->for($user)->create([
        'provider' => 'oidc:https://idp.test',
        'provider_id' => 'sub-legacy',
    ]);

    issuerNamespaceMigration()->down();

    $this->assertDatabaseHas('sso_identities', [
        'provider' => 'oidc',
        'provider_id' => 'sub-legacy',
    ]);
});

test('the migration is a no-op when no OIDC issuer is configured', function (): void {
    config(['services.oidc.issuer' => null]);
    $user = User::factory()->create();
    SsoIdentity::factory()->for($user)->create(['provider' => 'oidc', 'provider_id' => 'sub-legacy']);

    issuerNamespaceMigration()->up();

    $this->assertDatabaseHas('sso_identities', ['provider' => 'oidc', 'provider_id' => 'sub-legacy']);
});
