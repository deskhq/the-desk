<?php

declare(strict_types=1);

use App\Models\Channel;
use App\Models\Team;
use App\Models\UserGroup;
use App\Support\NameSlug;
use Illuminate\Support\Facades\DB;

/**
 * Load the slug-repair data migration as an instance so its up() can be
 * exercised against rows blanked behind the models' backs (RefreshDatabase has
 * already run it once, on empty tables, during setup).
 */
function repairEmptyChannelAndGroupSlugsMigration(): object
{
    return require base_path('database/migrations/2026_07_26_134641_repair_empty_channel_and_group_slugs.php');
}

/**
 * Blank a row's slug without going through the model guard.
 */
function blankSlug(string $table, string $id, string $slug = ''): void
{
    DB::table($table)->where('id', $id)->update(['slug' => $slug]);
}

test('the migration gives every blank-slug channel a usable slug', function (): void {
    $team = Team::factory()->create();
    $japanese = Channel::factory()->for($team)->create(['name' => '日本語']);
    $punctuation = Channel::factory()->for($team)->create(['name' => '<<<']);

    blankSlug('channels', $japanese->id);
    blankSlug('channels', $punctuation->id, '  ');

    repairEmptyChannelAndGroupSlugsMigration()->up();

    $slugs = collect([$japanese, $punctuation])->map(fn (Channel $channel): string => $channel->fresh()->slug);

    expect($slugs->filter(fn (string $slug): bool => trim($slug) === ''))->toBeEmpty()
        ->and($slugs->unique())->toHaveCount(2);
});

test('the migration gives every blank-slug group a usable handle', function (): void {
    $team = Team::factory()->create();
    $group = UserGroup::factory()->for($team)->create(['name' => '日本語']);

    blankSlug('user_groups', $group->id);

    repairEmptyChannelAndGroupSlugsMigration()->up();

    expect($group->fresh()->slug)->toMatch('/^[a-z0-9]+(?:-[a-z0-9]+)*$/');
});

test('the migration steps around a slug already taken in the team', function (): void {
    $team = Team::factory()->create();
    $occupied = NameSlug::distinct('日本語', Channel::FALLBACK_SLUG);

    $squatter = Channel::factory()->for($team)->create(['name' => 'Squatter', 'slug' => $occupied]);
    $blank = Channel::factory()->for($team)->create(['name' => '日本語']);

    blankSlug('channels', $blank->id);

    repairEmptyChannelAndGroupSlugsMigration()->up();

    expect($blank->fresh()->slug)->toBe($occupied.'-1')
        ->and($squatter->fresh()->slug)->toBe($occupied);
});

test('the migration leaves healthy slugs alone', function (): void {
    $team = Team::factory()->create();
    $channel = Channel::factory()->for($team)->create(['name' => 'Marketing', 'slug' => 'marketing']);
    $group = UserGroup::factory()->for($team)->create(['name' => 'Dev Team', 'slug' => 'dev-team']);

    repairEmptyChannelAndGroupSlugsMigration()->up();

    expect($channel->fresh()->slug)->toBe('marketing')
        ->and($group->fresh()->slug)->toBe('dev-team');
});
