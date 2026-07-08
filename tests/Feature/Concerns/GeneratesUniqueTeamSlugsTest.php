<?php

use App\Models\Team;

test('non numeric slug suffixes are ignored when choosing the next suffix', function () {
    // Matches the "acme-%" LIKE clause but not the numeric pattern, exercising the
    // null branch in the suffix mapper.
    Team::factory()->create(['name' => 'Acme Beta', 'slug' => 'acme-beta']);

    $team = Team::factory()->create(['name' => 'Acme', 'slug' => '']);

    expect($team->slug)->toBe('acme-1');
});

test('a fresh name keeps its bare slug', function () {
    $team = Team::factory()->create(['name' => 'Unique Co', 'slug' => '']);

    expect($team->slug)->toBe('unique-co');
});
