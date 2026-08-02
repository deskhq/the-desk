<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Load the orphaned-token prune as an instance so its up() can be exercised
 * against rows orphaned behind the observer's back (RefreshDatabase has already
 * run it once, on empty tables, during setup).
 */
function pruneOrphanedPersonalAccessTokensMigration(): object
{
    return require base_path('database/migrations/2026_08_01_101500_prune_orphaned_personal_access_tokens.php');
}

/**
 * Delete a user's row directly, bypassing the observer that now sweeps their
 * tokens — reproducing the state installs upgrading from before it accumulated.
 */
function orphanTokensOf(User $user): void
{
    DB::table('users')->where('id', $user->id)->delete();
}

test('the migration deletes tokens whose owner is gone', function (): void {
    $team = Team::factory()->create();
    $bot = User::factory()->bot($team)->create();

    $bot->createToken('CI', ['channels:read']);
    orphanTokensOf($bot);

    expect(DB::table('personal_access_tokens')->where('tokenable_id', $bot->id)->count())->toBe(1);

    pruneOrphanedPersonalAccessTokensMigration()->up();

    expect(DB::table('personal_access_tokens')->where('tokenable_id', $bot->id)->count())->toBe(0);
});

test('the migration leaves tokens with a living owner alone', function (): void {
    $team = Team::factory()->create();
    $bot = User::factory()->bot($team)->create();
    $human = User::factory()->create();

    $bot->createToken('CI', ['channels:read']);
    $human->createToken('Scripts', ['channels:read']);

    pruneOrphanedPersonalAccessTokensMigration()->up();

    expect(DB::table('personal_access_tokens')->where('tokenable_id', $bot->id)->count())->toBe(1)
        ->and(DB::table('personal_access_tokens')->where('tokenable_id', $human->id)->count())->toBe(1);
});

test('the migration leaves a tokenable type it does not own alone', function (): void {
    $team = Team::factory()->create();
    $bot = User::factory()->bot($team)->create();

    $bot->createToken('CI', ['channels:read']);

    // A hypothetical future tokenable: same table, an id that resolves against
    // its own table rather than `users`, so the prune must not judge it.
    DB::table('personal_access_tokens')
        ->where('tokenable_id', $bot->id)
        ->update(['tokenable_type' => 'App\Models\Widget']);

    pruneOrphanedPersonalAccessTokensMigration()->up();

    expect(DB::table('personal_access_tokens')->where('tokenable_id', $bot->id)->count())->toBe(1);
});
