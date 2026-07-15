<?php

use App\Actions\Teams\CreateTeam;
use App\Enums\TeamRole;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The settings sidebar surfaces a team-admin "evidence" group (Audit log,
 * Security log, Exports). Its visibility rides on two shared Inertia props,
 * gated by the same permissions as the Team-settings cards. These tests pin
 * that gating so an admin can reach the surfaces while a plain member cannot.
 */
test('an admin sees the team-evidence permissions on every settings page', function (): void {
    $owner = User::factory()->create();
    app(CreateTeam::class)->handle($owner, 'Acme');

    $this->actingAs($owner)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('canViewCurrentTeamAudit', true)
            ->where('canViewCurrentTeamSecurityLog', true)
        );
});

test('a plain member sees no team-evidence permissions', function (): void {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');

    $member = User::factory()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->switchTeam($team);

    $this->actingAs($member)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('canViewCurrentTeamAudit', false)
            ->where('canViewCurrentTeamSecurityLog', false)
        );
});

test('a personal team exposes no team-evidence permissions', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('canViewCurrentTeamAudit', false)
            ->where('canViewCurrentTeamSecurityLog', false)
        );
});
