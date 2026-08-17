<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Support\UserWorkspaces;
use App\Support\WorkspaceShell;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| What the fingerprints cost (#1253)
|--------------------------------------------------------------------------
|
| The last `once` group buys its correctness with a key, and a key has to be
| computed on every navigation — including the ones where it turns out to match
| and nothing ships. So the whole trade rests on that computation being an
| indexed aggregate rather than a hydrate, and nothing enforces that but a test
| that counts: this is the shared props' equivalent of
| `SidebarChannelsQueryCountTest` for the sidebar.
|
| Both pins below are constants rather than comparisons against a baseline, so
| a regression that adds a query whatever the workspace's size is caught as
| well as one that scales with it.
|
*/

/**
 * One aggregate per fingerprinted prop group: the member roster, the custom
 * emoji, the user groups, and the viewer's workspace list.
 *
 * Every one is a `count(*)` and a summed write clock over rows the database
 * never hands back, so this number is the whole per-navigation cost of the
 * group — the roster's hydrate and its payload having gone with it.
 */
const SHARED_PROP_FINGERPRINT_BUDGET = 4;

/**
 * Compute every key the fingerprinted props are shared under, and report the
 * queries it took.
 */
function fingerprintQueries(User $viewer, Team $team): int
{
    $shell = new WorkspaceShell($viewer, $team);
    $workspaces = new UserWorkspaces($viewer);

    DB::connection()->flushQueryLog();
    DB::connection()->enableQueryLog();

    $shell->teamMembersFingerprint();
    $shell->customEmojisFingerprint();
    $shell->userGroupsFingerprint();
    $workspaces->fingerprint();

    $queries = count(DB::connection()->getQueryLog());

    DB::connection()->disableQueryLog();

    return $queries;
}

/**
 * Draw both halves of the workspace list — every workspace, and which of them
 * is current — and report the queries it took.
 */
function workspaceListQueries(User $viewer): int
{
    $workspaces = new UserWorkspaces($viewer);

    DB::connection()->flushQueryLog();
    DB::connection()->enableQueryLog();

    $workspaces->all();
    $workspaces->current();

    $queries = count(DB::connection()->getQueryLog());

    DB::connection()->disableQueryLog();

    return $queries;
}

it('keys every fingerprinted prop off one aggregate each, whatever the workspace holds', function (): void {
    ['owner' => $viewer, 'team' => $team, 'channel' => $general] = teamWithChannel();

    $small = fingerprintQueries($viewer, $team);

    collect(range(1, 8))->each(fn (): User => teamMemberInChannel($general));

    $large = fingerprintQueries($viewer, $team);

    expect($small)->toBe(SHARED_PROP_FINGERPRINT_BUDGET)
        ->and($large)->toBe(SHARED_PROP_FINGERPRINT_BUDGET);
});

it('counts the members of each workspace once, and the current one no more than the rest', function (): void {
    $viewer = User::factory()->create();

    // One workspace: the list query and the viewer's standing in it. Its size
    // and that standing used to be resolved twice over — once for `teams` and
    // again for `currentTeam` — which is invisible here and an N+1 below.
    expect(workspaceListQueries($viewer->fresh()))->toBe(2);

    collect(range(1, 4))->each(function () use ($viewer): void {
        Team::factory()->create()->members()->attach($viewer, ['role' => TeamRole::Member->value]);
    });

    // Four more workspaces cost four more role lookups and nothing else: the
    // member counts ride the list query rather than costing one each, and the
    // current workspace is still read out of the list rather than asked for.
    expect(workspaceListQueries($viewer->fresh()))->toBe(6);
});
