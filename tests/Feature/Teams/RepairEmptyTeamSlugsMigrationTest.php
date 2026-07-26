<?php

use App\Models\Team;
use Illuminate\Support\Facades\DB;

/**
 * Load the slug-repair data migration as an instance so its up() can be
 * exercised against rows blanked behind the model's back (RefreshDatabase has
 * already run it once, on an empty table, during setup).
 */
function repairEmptyTeamSlugsMigration(): object
{
    return require base_path('database/migrations/2026_07_26_131207_repair_empty_team_slugs.php');
}

test('the migration gives every blank-slug team a usable, unique slug', function (): void {
    $japanese = Team::factory()->create(['name' => '日本語']);
    $punctuation = Team::factory()->create(['name' => '<<<']);

    DB::table('teams')->where('id', $japanese->id)->update(['slug' => '']);
    DB::table('teams')->where('id', $punctuation->id)->update(['slug' => '  ']);

    repairEmptyTeamSlugsMigration()->up();

    $repaired = collect([$japanese, $punctuation])
        ->map(fn (Team $team): string => $team->fresh()->slug)
        ->sort()
        ->values()
        ->all();

    expect($repaired)->toBe(['team', 'team-1']);
});

test('the migration repairs a soft-deleted team too', function (): void {
    $team = Team::factory()->trashed()->create(['name' => '팀']);

    DB::table('teams')->where('id', $team->id)->update(['slug' => '']);

    repairEmptyTeamSlugsMigration()->up();

    expect(Team::withTrashed()->find($team->id)->slug)->toBe('team');
});

test('the migration leaves healthy slugs alone', function (): void {
    $team = Team::factory()->create(['name' => 'Acme', 'slug' => 'acme']);

    repairEmptyTeamSlugsMigration()->up();

    expect($team->fresh()->slug)->toBe('acme');
});
