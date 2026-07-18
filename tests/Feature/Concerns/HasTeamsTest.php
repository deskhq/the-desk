<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;

test('personal team returns the users personal team', function (): void {
    $user = User::factory()->create();

    $personal = $user->personalTeam();

    expect($personal)->not->toBeNull()
        ->and($personal->is_personal)->toBeTrue()
        ->and($personal->id)->toBe($user->currentTeam->id);
});

test('owned teams returns only teams the user owns', function (): void {
    $user = User::factory()->create();
    $personal = $user->currentTeam;

    $ownedTeam = Team::factory()->create();
    $ownedTeam->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $memberTeam = Team::factory()->create();
    $memberTeam->members()->attach($user, ['role' => TeamRole::Member->value]);

    $ownedIds = $user->ownedTeams()->pluck('teams.id');

    expect($ownedIds)->toContain($personal->id)
        ->toContain($ownedTeam->id)
        ->not->toContain($memberTeam->id);
});

test('switching to a team the user does not belong to returns false', function (): void {
    $user = User::factory()->create();
    $current = $user->currentTeam;

    $foreignTeam = Team::factory()->create();

    expect($user->switchTeam($foreignTeam))->toBeFalse()
        ->and($user->refresh()->current_team_id)->toBe($current->id);
});

test('fallback team returns the first team ordered by name', function (): void {
    // Name the user last so their personal team never wins the ordering.
    $user = User::factory()->create(['name' => 'Zzz']);

    $alpha = Team::factory()->create(['name' => 'Alpha']);
    $zulu = Team::factory()->create(['name' => 'Zulu']);

    foreach ([$alpha, $zulu] as $team) {
        $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    }

    expect($user->fallbackTeam()->id)->toBe($alpha->id);
});

test('user team dto carries the team member count', function (): void {
    $user = User::factory()->create();

    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $team->members()->attach(User::factory()->create(), ['role' => TeamRole::Member->value]);

    expect($user->toUserTeam($team)->membersCount)->toBe(2);
});

test('team permissions grant integration management to admins of a real team only', function (): void {
    $admin = User::factory()->create();
    $member = User::factory()->create();

    $team = Team::factory()->create();
    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    expect($admin->toTeamPermissions($team)->canManageIntegrations)->toBeTrue()
        ->and($member->toTeamPermissions($team)->canManageIntegrations)->toBeFalse()
        ->and($admin->toTeamPermissions($admin->personalTeam())->canManageIntegrations)->toBeFalse();
});

test('fallback team can exclude a given team', function (): void {
    $user = User::factory()->create(['name' => 'Zzz']);

    $alpha = Team::factory()->create(['name' => 'Alpha']);
    $bravo = Team::factory()->create(['name' => 'Bravo']);

    foreach ([$alpha, $bravo] as $team) {
        $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    }

    expect($user->fallbackTeam(excluding: $alpha)->id)->toBe($bravo->id);
});
