<?php

use App\Data\TeamPermissions;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/*
|--------------------------------------------------------------------------
| The permissions DTO projects from the gate (#1198)
|--------------------------------------------------------------------------
|
| Every field of `TeamPermissions` is an ability the policies already hold, so
| the projection asks them rather than re-stating them. It used to re-state
| them, and `canDeleteTeam` drifted: `TeamPolicy::delete()` refuses a personal
| workspace, the copy did not, and `teams/Edit.vue` patched the difference back
| out in the template.
|
| This file is what keeps the projection honest. The map below names the ability
| behind each field; the first test proves it covers the DTO exhaustively, so a
| new permission with no gate behind it fails here rather than shipping as a
| sixteenth hand-written clause.
|
*/

/**
 * The ability behind each field of {@see TeamPermissions}.
 *
 * @return array<string, Closure(User, Team): bool>
 */
function teamPermissionGates(): array
{
    return [
        'canUpdateTeam' => fn (User $user, Team $team): bool => Gate::forUser($user)->allows('update', $team),
        'canDeleteTeam' => fn (User $user, Team $team): bool => Gate::forUser($user)->allows('delete', $team),
        'canAddMember' => fn (User $user, Team $team): bool => Gate::forUser($user)->allows('addMember', $team),
        'canUpdateMember' => fn (User $user, Team $team): bool => Gate::forUser($user)->allows('updateMember', $team),
        'canRemoveMember' => fn (User $user, Team $team): bool => Gate::forUser($user)->allows('removeMember', $team),
        'canCreateInvitation' => fn (User $user, Team $team): bool => Gate::forUser($user)->allows('inviteMember', $team),
        'canCancelInvitation' => fn (User $user, Team $team): bool => Gate::forUser($user)->allows('cancelInvitation', $team),
        'canTransferOwnership' => fn (User $user, Team $team): bool => Gate::forUser($user)->allows('transferOwnership', $team),
        'canViewAudit' => fn (User $user, Team $team): bool => Gate::forUser($user)->allows('viewAudit', $team),
        'canViewSecurityLog' => fn (User $user, Team $team): bool => Gate::forUser($user)->allows('viewSecurityLog', $team),
        'canViewAnalytics' => fn (User $user, Team $team): bool => Gate::forUser($user)->allows('viewAnalytics', $team),
        'canViewDeletedChannels' => fn (User $user, Team $team): bool => Gate::forUser($user)->allows('viewDeletedChannels', $team),
        'canManageEmojis' => fn (User $user, Team $team): bool => Gate::forUser($user)->allows('manageEmojis', $team),
        'canManageIntegrations' => fn (User $user, Team $team): bool => Gate::forUser($user)->allows('manageIntegrations', $team),
        'canManageUserGroups' => fn (User $user, Team $team): bool => Gate::forUser($user)->allows('viewAny', [UserGroup::class, $team]),
    ];
}

/**
 * A workspace of the given kind with the user holding the given role on it, or
 * holding none at all when the role is null.
 */
function workspaceFor(User $user, ?TeamRole $role, bool $isPersonal): Team
{
    $team = ($isPersonal ? Team::factory()->personal() : Team::factory())->create();

    if ($role instanceof TeamRole) {
        $team->members()->attach($user, ['role' => $role->value]);
    }

    return $team;
}

test('every field of the permissions DTO names an ability', function (): void {
    $fields = array_map(
        fn (ReflectionParameter $parameter): string => $parameter->getName(),
        (new ReflectionClass(TeamPermissions::class))->getConstructor()?->getParameters() ?? [],
    );

    expect(array_keys(teamPermissionGates()))->toEqualCanonicalizing($fields);
});

test('every field of the permissions DTO answers what its gate answers', function (?TeamRole $role, bool $isPersonal): void {
    $user = User::factory()->create();
    $team = workspaceFor($user, $role, $isPersonal);

    $permissions = $user->toTeamPermissions($team);

    $projected = [];
    $granted = [];

    foreach (teamPermissionGates() as $field => $gate) {
        $projected[$field] = $permissions->{$field};
        $granted[$field] = $gate($user, $team);
    }

    expect($projected)->toBe($granted);
})->with([
    'the owner of a real workspace' => [TeamRole::Owner, false],
    'an admin of a real workspace' => [TeamRole::Admin, false],
    'a member of a real workspace' => [TeamRole::Member, false],
    'the owner of a personal workspace' => [TeamRole::Owner, true],
    'an admin of a personal workspace' => [TeamRole::Admin, true],
    'a member of a personal workspace' => [TeamRole::Member, true],
    'someone who does not belong to the workspace' => [null, false],
]);

test('the owner of a personal workspace may not delete it', function (): void {
    $user = User::factory()->create();
    $personal = $user->personalTeam();

    expect($user->toTeamPermissions($personal)->canDeleteTeam)->toBeFalse()
        ->and(Gate::forUser($user)->allows('delete', $personal))->toBeFalse();
});

test('the projection resolves the role once, not once per ability', function (): void {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Admin->value]);

    DB::connection()->flushQueryLog();
    DB::connection()->enableQueryLog();

    $user->toTeamPermissions($team);

    $queries = DB::connection()->getQueryLog();
    DB::connection()->disableQueryLog();

    expect($queries)->toHaveCount(1);
});

test('a role written after a projection is answered fresh', function (): void {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);

    expect($user->toTeamPermissions($team)->canUpdateTeam)->toBeFalse();

    $team->members()->updateExistingPivot($user->id, ['role' => TeamRole::Admin->value]);

    expect($user->toTeamPermissions($team)->canUpdateTeam)->toBeTrue();
});
