<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Policies\TeamPolicy;

beforeEach(function (): void {
    $this->policy = new TeamPolicy;
});

test('any user can view the team index and create teams', function (): void {
    $user = User::factory()->create();

    expect($this->policy->viewAny($user))->toBeTrue()
        ->and($this->policy->create($user))->toBeTrue();
});

test('only members can view a team', function (): void {
    $member = User::factory()->create();
    $outsider = User::factory()->create();

    $team = Team::factory()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    expect($this->policy->view($member, $team))->toBeTrue()
        ->and($this->policy->view($outsider, $team))->toBeFalse();
});

test('adding members requires the add member permission', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();

    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    expect($this->policy->addMember($owner, $team))->toBeTrue()
        ->and($this->policy->addMember($member, $team))->toBeFalse();
});

test('managing integrations is scoped to owners and admins of a real team', function (): void {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $outsider = User::factory()->create();

    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    expect($this->policy->manageIntegrations($owner, $team))->toBeTrue()
        ->and($this->policy->manageIntegrations($admin, $team))->toBeTrue()
        ->and($this->policy->manageIntegrations($member, $team))->toBeFalse()
        ->and($this->policy->manageIntegrations($outsider, $team))->toBeFalse();
});

test('managing integrations is never allowed on a personal team', function (): void {
    $user = User::factory()->create();
    $personal = $user->personalTeam();

    expect($this->policy->manageIntegrations($user, $personal))->toBeFalse();
});
