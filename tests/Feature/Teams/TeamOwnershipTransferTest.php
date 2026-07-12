<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;

test('an owner can transfer ownership to another member', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $response = $this
        ->actingAs($owner)
        ->post(route('teams.members.transfer-ownership', [$team, $member]), [
            'password' => 'password',
        ]);

    $response->assertRedirect(route('teams.edit', $team));

    expect($owner->fresh()->teamRole($team))->toBe(TeamRole::Admin);
    expect($member->fresh()->teamRole($team))->toBe(TeamRole::Owner);
});

test('the single owner invariant is preserved after a transfer', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this
        ->actingAs($owner)
        ->post(route('teams.members.transfer-ownership', [$team, $member]), [
            'password' => 'password',
        ]);

    expect($team->members()->wherePivot('role', TeamRole::Owner->value)->count())->toBe(1);
    expect($team->owner()->is($member))->toBeTrue();
});

test('a non owner cannot transfer ownership', function (): void {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $response = $this
        ->actingAs($admin)
        ->post(route('teams.members.transfer-ownership', [$team, $member]), [
            'password' => 'password',
        ]);

    $response->assertForbidden();

    expect($owner->fresh()->teamRole($team))->toBe(TeamRole::Owner);
});

test('ownership cannot be transferred to a non member', function (): void {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $response = $this
        ->actingAs($owner)
        ->post(route('teams.members.transfer-ownership', [$team, $stranger]), [
            'password' => 'password',
        ]);

    $response->assertNotFound();

    expect($owner->fresh()->teamRole($team))->toBe(TeamRole::Owner);
});

test('ownership cannot be transferred to yourself', function (): void {
    $owner = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $response = $this
        ->actingAs($owner)
        ->post(route('teams.members.transfer-ownership', [$team, $owner]), [
            'password' => 'password',
        ]);

    $response->assertForbidden();

    expect($owner->fresh()->teamRole($team))->toBe(TeamRole::Owner);
});

test('transferring ownership requires the current password', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $response = $this
        ->actingAs($owner)
        ->from(route('teams.edit', $team))
        ->post(route('teams.members.transfer-ownership', [$team, $member]), [
            'password' => 'wrong-password',
        ]);

    $response->assertSessionHasErrors('password');

    expect($owner->fresh()->teamRole($team))->toBe(TeamRole::Owner);
    expect($member->fresh()->teamRole($team))->toBe(TeamRole::Member);
});
