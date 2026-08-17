<?php

declare(strict_types=1);

use App\Models\Team;

test('non numeric slug suffixes are ignored when choosing the next suffix', function (): void {
    // Matches the "acme-%" LIKE clause but not the numeric pattern, exercising the
    // null branch in the suffix mapper.
    Team::factory()->create(['name' => 'Acme Beta', 'slug' => 'acme-beta']);

    $team = Team::factory()->create(['name' => 'Acme', 'slug' => '']);

    expect($team->slug)->toBe('acme-1');
});

test('a fresh name keeps its bare slug', function (): void {
    $team = Team::factory()->create(['name' => 'Unique Co', 'slug' => '']);

    expect($team->slug)->toBe('unique-co');
});

test('a name that slugs to nothing falls back to a usable slug', function (string $name): void {
    $team = Team::factory()->create(['name' => $name, 'slug' => '']);

    expect($team->slug)->toBe('team');
})->with([
    'japanese' => ['日本語'],
    'korean' => ['팀'],
    'hebrew' => ['צוות'],
    'punctuation only' => ['<<<'],
    'emoji only' => ['🎉'],
]);

test('two names that slug to nothing get distinct slugs', function (): void {
    $first = Team::factory()->create(['name' => '日本語', 'slug' => '']);
    $second = Team::factory()->create(['name' => '中文团队', 'slug' => '']);
    $third = Team::factory()->create(['name' => '!!!', 'slug' => '']);

    expect([$first->slug, $second->slug, $third->slug])->toBe(['team', 'team-1', 'team-2']);
});

test('renaming to a name that slugs to nothing falls back to a usable slug', function (string $name): void {
    $team = Team::factory()->create(['name' => 'Acme', 'slug' => 'acme']);

    $team->update(['name' => $name]);

    expect($team->fresh()->slug)->toBe('team');
})->with([
    'japanese' => ['日本語'],
    'punctuation only' => ['<<<'],
]);

test('a slug blanked without a rename is regenerated from the name', function (): void {
    $team = Team::factory()->create(['name' => 'Acme', 'slug' => 'acme']);

    $team->update(['slug' => '   ']);

    expect($team->fresh()->slug)->toBe('acme');
});

test('a name transliterated by the slugger keeps its transliteration', function (string $name, string $slug): void {
    $team = Team::factory()->create(['name' => $name, 'slug' => '']);

    expect($team->slug)->toBe($slug);
})->with([
    'cyrillic' => ['Привет мир', 'privet-mir'],
    'greek' => ['Ελλάδα', 'ellada'],
]);
