<?php

use App\Enums\AppLocale;
use App\Models\CustomEmoji;
use App\Models\UserGroup;

/*
|--------------------------------------------------------------------------
| The props a third party mutates (#1253)
|--------------------------------------------------------------------------
|
| The last of the three `once` groups, after the instance constants of #1251
| and the viewer's own writes of #1252. What is left is the residue neither of
| those reaches: rosters a *teammate* moves. No write of the viewer's fires, so
| there is no partial reload to piggyback on, and two of them — `customEmojis`
| and `userGroups` — have no invalidation trigger at all today, being read-only
| everywhere they are consumed.
|
| Their trigger is therefore built rather than borrowed: each key carries a
| cheap aggregate fingerprint over the prop's source rows, so a changed roster
| is a changed key and the next navigation ships it. Where an Echo event exists
| it still fires a targeted reload for an instant refresh, but the fingerprint
| underneath is what makes a *missed* event self-heal — which is the whole
| point on a self-hosted instance, where Reverb is the piece most likely to be
| misconfigured.
|
| Every test here therefore proves the fingerprint alone: the suite runs with
| `BROADCAST_CONNECTION=null` (pinned below), so nothing an assertion sees can
| have come from a broadcast.
|
| The fingerprints are never asserted directly. A test naming a hash literal
| asserts the implementation and fails on the next honest refactor, so the
| claim is always made through the visit: change the source rows, and the prop
| comes back.
|
*/

/**
 * The rosters whose answer is one workspace's, carried only inside one.
 *
 * @var list<string>
 */
const TEAM_ONCE_PROPS = [
    'teamMembers',
    'customEmojis',
    'userGroups',
];

/**
 * The rosters whose answer is the viewer's, carried on every route — the
 * workspace switcher draws them from the settings pages too.
 *
 * @var list<string>
 */
const WORKSPACE_LIST_ONCE_PROPS = [
    'teams',
    'currentTeam',
];

test('the suite proves these props without a broadcaster', function (): void {
    // The premise of every test below, pinned rather than assumed: an
    // assertion that a change reached the client can only be about the
    // fingerprint when there is no connection for an event to have taken.
    // `BROADCAST_CONNECTION=null` in `phpunit.xml`, which `env()` reads back as
    // PHP null rather than as the string.
    expect(config('broadcasting.default'))->toBeNull();
});

test('a first load carries every third-party-mutable prop', function (): void {
    ['owner' => $viewer, 'team' => $team] = teamWithChannel();

    $page = visitWorkspaceAs($viewer, $team)->assertOk()->viewData('page');

    expect(array_keys($page['props']))
        ->toContain(...TEAM_ONCE_PROPS)
        ->toContain(...WORKSPACE_LIST_ONCE_PROPS);
});

test('a second visit with nothing changed carries none of them', function (): void {
    ['owner' => $viewer, 'team' => $team] = teamWithChannel();

    $first = visitWorkspaceAs($viewer, $team)->assertOk();

    $props = array_keys((array) revisit($first, workspaceUrl($team))->assertOk()->json('props'));

    expect(array_intersect([...TEAM_ONCE_PROPS, ...WORKSPACE_LIST_ONCE_PROPS], $props))->toBe([]);
});

test('a partial reload naming one of them still receives it', function (string $prop): void {
    ['owner' => $viewer, 'team' => $team] = teamWithChannel();

    $first = visitWorkspaceAs($viewer, $team)->assertOk();

    expect(array_keys((array) partialRevisit($first, workspaceUrl($team), [$prop])->assertOk()->json('props')))
        ->toContain($prop);
})->with([...TEAM_ONCE_PROPS, ...WORKSPACE_LIST_ONCE_PROPS]);

test('a member joining reaches the next navigation', function (): void {
    ['owner' => $viewer, 'team' => $team, 'channel' => $general] = teamWithChannel();

    $first = visitWorkspaceAs($viewer, $team)->assertOk();

    teamMemberInChannel($general, ['name' => 'Amy Member']);

    expect(collect((array) revisit($first, workspaceUrl($team))->assertOk()->json('props.teamMembers'))
        ->pluck('name'))
        ->toContain('Amy Member');
});

test('a member leaving reaches the next navigation', function (): void {
    ['owner' => $viewer, 'team' => $team, 'channel' => $general] = teamWithChannel();

    $leaver = teamMemberInChannel($general, ['name' => 'Amy Member']);

    $first = visitWorkspaceAs($viewer, $team)->assertOk();

    // A deletion moves no write clock forward at all, which is why the count
    // sits in the key beside it: the departure is only visible as one fewer row.
    $team->memberships()->where('user_id', $leaver->id)->delete();

    expect(collect((array) revisit($first, workspaceUrl($team))->assertOk()->json('props.teamMembers'))
        ->pluck('name'))
        ->not->toContain('Amy Member');
});

test('a member editing their own profile reaches other clients', function (): void {
    ['owner' => $viewer, 'team' => $team, 'channel' => $general] = teamWithChannel();

    $mate = teamMemberInChannel($general, ['name' => 'Amy Member']);

    $first = visitWorkspaceAs($viewer, $team)->assertOk();

    // Eloquent persists `updated_at` at second resolution, so a rename in the
    // same second as the row was created leaves the column — and any digest
    // over it — untouched. That is the fingerprint's real granularity rather
    // than something the test works around, so the clock moves instead.
    $this->travel(1)->second();

    $mate->update(['name' => 'Amy Renamed']);

    expect(collect((array) revisit($first, workspaceUrl($team))->assertOk()->json('props.teamMembers'))
        ->pluck('name'))
        ->toContain('Amy Renamed');
});

test('a custom emoji being added reaches the next navigation', function (): void {
    ['owner' => $viewer, 'team' => $team] = teamWithChannel();

    $first = visitWorkspaceAs($viewer, $team)->assertOk();

    CustomEmoji::factory()->for($team)->create(['name' => 'shipit']);

    expect(revisit($first, workspaceUrl($team))->assertOk()->json('props.customEmojis'))
        ->toHaveKey('shipit');
});

test('a custom emoji being revoked reaches the next navigation', function (): void {
    ['owner' => $viewer, 'team' => $team] = teamWithChannel();

    $emoji = CustomEmoji::factory()->for($team)->create(['name' => 'shipit']);

    $first = visitWorkspaceAs($viewer, $team)->assertOk();

    $emoji->delete();

    expect(revisit($first, workspaceUrl($team))->assertOk()->json('props.customEmojis'))->toBe([]);
});

test('a user group being created reaches the next navigation', function (): void {
    ['owner' => $viewer, 'team' => $team] = teamWithChannel();

    $first = visitWorkspaceAs($viewer, $team)->assertOk();

    UserGroup::factory()->for($team)->create(['name' => 'Dev Team', 'slug' => 'dev-team']);

    expect(collect((array) revisit($first, workspaceUrl($team))->assertOk()->json('props.userGroups'))
        ->pluck('slug'))
        ->toContain('dev-team');
});

test('a user group being deleted reaches the next navigation', function (): void {
    ['owner' => $viewer, 'team' => $team] = teamWithChannel();

    $group = UserGroup::factory()->for($team)->create(['name' => 'Dev Team', 'slug' => 'dev-team']);

    $first = visitWorkspaceAs($viewer, $team)->assertOk();

    $group->delete();

    expect(revisit($first, workspaceUrl($team))->assertOk()->json('props.userGroups'))->toBe([]);
});

test('someone joining a group moves the count the mention menu draws', function (): void {
    ['owner' => $viewer, 'team' => $team, 'channel' => $general] = teamWithChannel();

    $group = UserGroup::factory()->for($team)->create(['name' => 'Dev Team', 'slug' => 'dev-team']);
    $mate = teamMemberInChannel($general);

    $first = visitWorkspaceAs($viewer, $team)->assertOk();

    // The group's own row does not move when its membership does, so the
    // fingerprint reaches through to the pivot: `membersCount` is on the prop.
    $group->members()->attach($mate->id);

    expect(revisit($first, workspaceUrl($team))->assertOk()->json('props.userGroups.0.membersCount'))
        ->toBe(1);
});

test('a workspace being renamed reaches the next navigation', function (): void {
    ['owner' => $viewer, 'team' => $team] = teamWithChannel();

    $first = visitWorkspaceAs($viewer, $team)->assertOk();

    // Second resolution again — see the profile rename above.
    $this->travel(1)->second();

    $team->update(['name' => 'Acme Renamed']);

    expect(revisit($first, workspaceUrl($team))->assertOk()->json('props.currentTeam.name'))
        ->toBe('Acme Renamed');
});

test('someone joining the workspace moves the size the switcher draws', function (): void {
    ['owner' => $viewer, 'team' => $team, 'channel' => $general] = teamWithChannel();

    $first = visitWorkspaceAs($viewer, $team)->assertOk();

    teamMemberInChannel($general);

    expect(revisit($first, workspaceUrl($team))->assertOk()->json('props.currentTeam.membersCount'))
        ->toBe(2);
});

test('joining another workspace reaches the next navigation', function (): void {
    ['owner' => $viewer, 'team' => $team] = teamWithChannel();
    ['team' => $other, 'channel' => $otherGeneral] = teamWithChannel('Globex');

    $first = visitWorkspaceAs($viewer, $team)->assertOk();

    joinTeamAndChannel($otherGeneral, $viewer);

    expect(collect((array) revisit($first, workspaceUrl($team))->assertOk()->json('props.teams'))
        ->pluck('name'))
        ->toContain($other->name);
});

test('the workspace rosters are absent off a workspace route', function (): void {
    ['owner' => $viewer] = teamWithChannel();

    $props = (array) $this->actingAs($viewer)->get(route('profile.edit'))->assertOk()->viewData('page')['props'];

    // Absent rather than empty, for the reason the rosters of #1252 are: a once
    // prop has one value per prop path for the life of the page, so an empty
    // roster shipped from a settings page would be the answer a later workspace
    // visit restored. Every consumer already defaults it.
    expect(array_intersect(TEAM_ONCE_PROPS, array_keys($props)))->toBe([]);
});

test('the workspace list is carried off a workspace route too', function (): void {
    ['owner' => $viewer] = teamWithChannel();

    $props = (array) $this->actingAs($viewer)->get(route('profile.edit'))->assertOk()->viewData('page')['props'];

    // Unlike the three above, these are the viewer's own list rather than one
    // workspace's, and the settings pages draw them — so there is one honest
    // answer everywhere and it is shared everywhere.
    expect(array_keys($props))->toContain(...WORKSPACE_LIST_ONCE_PROPS);
});

test('switching workspace reships the list under its new standing', function (): void {
    ['owner' => $viewer, 'team' => $team] = teamWithChannel();
    ['team' => $other, 'channel' => $otherGeneral] = teamWithChannel('Globex');
    joinTeamAndChannel($otherGeneral, $viewer);

    $first = visitWorkspaceAs($viewer, $team)->assertOk();

    // Which workspace is current is not a roster change at all, so it rides the
    // key in its own right: without it the switch would be excluded under the
    // key the client already holds and the rail would keep its old tile lit.
    $viewer->switchTeam($other);

    expect(revisit($first, workspaceUrl($other))->assertOk()->json('props.currentTeam.slug'))
        ->toBe($other->slug);
});

test('a guest is answered under a key the account that signs in does not hold', function (): void {
    $page = (array) $this->get(route('login'))->assertOk()->viewData('page');

    expect($page['props']['teams'])->toBe([])
        ->and($page['props']['currentTeam'])->toBeNull();
});

test('the workspace list follows the language it is labelled in', function (): void {
    ['owner' => $viewer, 'team' => $team] = teamWithChannel();

    $first = visitWorkspaceAs($viewer, $team)->assertOk();

    // `roleLabel` is translated server-side, so the language the viewer reads in
    // is part of the answer — the same reason `invitableRoles` carries it. Going
    // *back* to a language the client has already been answered in is what
    // `LOCALE_PROPS` names these for; the key alone only answers moving to a new
    // one.
    $viewer->update(['locale' => AppLocale::French->value]);

    expect(revisit($first, workspaceUrl($team))->assertOk()->json('props.currentTeam.roleLabel'))
        ->toBe('Propriétaire');
});

test('the current workspace is read out of the list rather than beside it', function (): void {
    ['owner' => $viewer, 'team' => $team] = teamWithChannel();
    ['team' => $other, 'channel' => $otherGeneral] = teamWithChannel('Globex');
    joinTeamAndChannel($otherGeneral, $viewer);

    $props = (array) visitWorkspaceAs($viewer, $team)->assertOk()->viewData('page')['props'];

    // One derivation feeding both props, which is what stops the current
    // workspace having its role looked up and its members counted a second
    // time for a row already sitting in `teams`.
    expect($props['currentTeam']->id)->toBe($team->id)
        ->and(collect($props['teams'])->pluck('id'))->toContain($team->id, $other->id);
});
