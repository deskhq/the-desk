<?php

use App\Enums\ChannelCreationPolicy;
use App\Enums\ChannelVisibility;
use App\Enums\TeamRole;
use App\Models\User;
use App\Support\WorkspacePermissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| The workspace permission derivation (#1250)
|--------------------------------------------------------------------------
|
| One derivation answers what the viewer may do in the workspace they are in,
| and six shared props read it. This file states the derivation: what it
| answers, what it answers when there is nobody or no workspace to answer for,
| and that asking it twice costs one lookup rather than two.
|
| `tests/Feature/Channels/WorkspacePermissionPropsTest.php` keeps the HTTP
| half — which props a visit ships, and that the visit derives once.
|
*/

/**
 * The number of membership-role lookups the callback provokes.
 *
 * `teamRole()` reads the pivot row directly, so it is the one query in the
 * derivation that starts `select * from "team_members"`; the policies' own
 * `belongsToTeam()` checks join it from `teams` behind a `select exists`.
 */
function countTeamRoleLookups(Closure $callback): int
{
    $lookups = 0;

    DB::listen(function ($query) use (&$lookups): void {
        if (str_starts_with($query->sql, 'select * from "team_members" where')) {
            $lookups++;
        }
    });

    $callback();

    return $lookups;
}

test('the derivation answers what the viewer may do in their current workspace', function (): void {
    ['owner' => $viewer, 'team' => $team] = teamWithChannel();
    $viewer->switchTeam($team);

    $permissions = new WorkspacePermissions($viewer);

    expect($permissions->forCurrentTeam()?->canViewAudit)->toBeTrue()
        ->and($permissions->creatableChannelVisibilities())->toBe([
            ChannelVisibility::Public->value,
            ChannelVisibility::Private->value,
        ]);
});

test('the derivation withholds a visibility the workspace reserves for admins', function (): void {
    ['team' => $team, 'channel' => $general] = teamWithChannel();
    $team->update(['private_channel_creation_policy' => ChannelCreationPolicy::Admins]);

    $member = teamMemberInChannel($general, [], TeamRole::Member);
    $member->switchTeam($team);

    expect(new WorkspacePermissions($member)->creatableChannelVisibilities())
        ->toBe([ChannelVisibility::Public->value]);
});

test('there is nothing to derive for a guest', function (): void {
    $permissions = new WorkspacePermissions(null);

    expect($permissions->forCurrentTeam())->toBeNull()
        ->and($permissions->creatableChannelVisibilities())->toBe([]);
});

test('there is nothing to derive for an account with no current workspace', function (): void {
    $user = User::factory()->create();
    $user->forceFill(['current_team_id' => null])->save();

    $permissions = new WorkspacePermissions($user->fresh());

    expect($permissions->forCurrentTeam())->toBeNull()
        ->and($permissions->creatableChannelVisibilities())->toBe([]);
});

test('the derivation is built from the request that asked for it', function (): void {
    ['owner' => $viewer, 'team' => $team] = teamWithChannel();
    $viewer->switchTeam($team);

    $request = Request::create('/');
    $request->setUserResolver(fn (): User => $viewer);

    expect(WorkspacePermissions::forRequest($request)->forCurrentTeam()?->canUpdateTeam)->toBeTrue()
        ->and(WorkspacePermissions::forRequest(Request::create('/'))->forCurrentTeam())->toBeNull();
});

test('both projections come off one role lookup, however often they are read', function (): void {
    ['owner' => $viewer, 'team' => $team] = teamWithChannel();
    $viewer->switchTeam($team);

    $permissions = new WorkspacePermissions($viewer);

    $lookups = countTeamRoleLookups(function () use ($permissions): void {
        // The six props read the derivation between them; reading it six times
        // has to cost what reading it once costs.
        $permissions->forCurrentTeam();
        $permissions->forCurrentTeam();
        $permissions->creatableChannelVisibilities();
        $permissions->creatableChannelVisibilities();
    });

    expect($lookups)->toBe(1);
});

test('a role rewritten between two derivations is answered from the database', function (): void {
    ['team' => $team, 'channel' => $general] = teamWithChannel();
    $member = teamMemberInChannel($general, [], TeamRole::Member);
    $member->switchTeam($team);

    expect(new WorkspacePermissions($member)->forCurrentTeam()?->canViewAudit)->toBeFalse();

    $team->memberships()->where('user_id', $member->id)->update(['role' => TeamRole::Admin]);

    // The held role dies with the derivation that held it, so the next one sees
    // the promotion rather than a copy taken before it.
    expect(new WorkspacePermissions($member)->forCurrentTeam()?->canViewAudit)->toBeTrue();
});
