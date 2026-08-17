<?php

declare(strict_types=1);

use App\Data\UserGroupData;
use App\Models\UserGroup;

/*
|--------------------------------------------------------------------------
| The mentionable group vocabulary (#1117)
|--------------------------------------------------------------------------
|
| What the composer's `@` menu offers and what a `group:` token in a message
| body resolves against. `UserGroupData::mentionableForTeam()` is a static call
| over a team, so which groups it lists, in what order, and how much of each one
| it carries can all be stated here rather than read back out of a rendered
| `channels/Show` — `tests/Feature/Channels/GroupMentionTest.php` keeps the HTTP
| half, that the workspace ships `userGroups` at all and that it is the
| roster-less variant it ships.
|
| The groups are built from the factory inline rather than through a local
| helper: a second global `userGroupWith()` would collide with the one that file
| already declares, and there is nothing to arrange here but the group itself.
|
*/

test('a group is listed by its identity and its size', function (): void {
    ['team' => $team, 'channel' => $general] = teamWithChannel();
    $ada = teamMemberInChannel($general, ['name' => 'Ada Lovelace']);
    $group = UserGroup::factory()->for($team)->slug('dev-team')->create();
    $group->members()->sync([$ada->id]);

    $listed = UserGroupData::mentionableForTeam($team);

    expect($listed)->toHaveCount(1)
        ->and($listed[0]->id)->toBe($group->id)
        ->and($listed[0]->name)->toBe($group->name)
        ->and($listed[0]->slug)->toBe('dev-team')
        ->and($listed[0]->membersCount)->toBe(1);
});

test('the roster stays off the mentionable list however many members a group has', function (): void {
    ['team' => $team, 'channel' => $general] = teamWithChannel();
    $ada = teamMemberInChannel($general, ['name' => 'Ada Lovelace']);
    $grace = teamMemberInChannel($general, ['name' => 'Grace Hopper']);
    UserGroup::factory()->for($team)->slug('dev-team')->create()
        ->members()->sync([$ada->id, $grace->id]);

    $listed = UserGroupData::mentionableForTeam($team);

    // Resolving a pill and listing the menu only ever need the handle and the
    // count, so the members themselves are withheld rather than shipped to
    // every reader of every message.
    expect($listed[0]->membersCount)->toBe(2)
        ->and($listed[0]->members)->toBe([]);
});

test('an empty group is still mentionable', function (): void {
    ['team' => $team] = teamWithChannel();
    UserGroup::factory()->for($team)->slug('dev-team')->create();

    $listed = UserGroupData::mentionableForTeam($team);

    expect($listed)->toHaveCount(1)
        ->and($listed[0]->membersCount)->toBe(0)
        ->and($listed[0]->members)->toBe([]);
});

test('the list is ordered by handle, not by creation', function (): void {
    ['team' => $team] = teamWithChannel();

    foreach (['ops-team', 'dev-team', 'analytics'] as $slug) {
        UserGroup::factory()->for($team)->slug($slug)->create();
    }

    expect(array_column(UserGroupData::mentionableForTeam($team), 'slug'))
        ->toBe(['analytics', 'dev-team', 'ops-team']);
});

test('a group belonging to another workspace is not mentionable in this one', function (): void {
    ['team' => $team] = teamWithChannel();
    ['team' => $otherTeam] = teamWithChannel('Globex');
    $ours = UserGroup::factory()->for($team)->slug('dev-team')->create();
    UserGroup::factory()->for($otherTeam)->slug('dev-team')->create();

    expect(array_column(UserGroupData::mentionableForTeam($team), 'id'))->toBe([$ours->id]);
});
