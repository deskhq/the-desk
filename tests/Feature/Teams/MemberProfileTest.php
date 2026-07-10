<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('a team member can view another member profile', function () {
    $viewer = User::factory()->create();
    $member = User::factory()->create([
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'pronouns' => 'she/her',
        'title' => 'Mathematician',
        'phone' => '+1 555 000 1815',
    ]);
    $team = Team::factory()->create();

    $team->members()->attach($viewer, ['role' => TeamRole::Member->value]);
    $team->members()->attach($member, ['role' => TeamRole::Admin->value]);

    $this->actingAs($viewer)
        ->get(route('teams.members.show', [$team, $member]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('teams/MemberProfile')
            ->where('team.slug', $team->slug)
            ->where('profile.id', $member->id)
            ->where('profile.name', 'Ada Lovelace')
            ->where('profile.email', 'ada@example.com')
            ->where('profile.pronouns', 'she/her')
            ->where('profile.title', 'Mathematician')
            ->where('profile.phone', '+1 555 000 1815')
            ->where('profile.role', TeamRole::Admin->value)
            ->where('profile.roleLabel', 'Admin')
            ->where('profile.isYou', false)
            ->whereNot('profile.memberSince', null)
        );
});

test('a member viewing their own profile is flagged as you', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $this->actingAs($user)
        ->get(route('teams.members.show', [$team, $user]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('profile.isYou', true));
});

test('viewing a user who does not belong to the team returns 404', function () {
    $viewer = User::factory()->create();
    $outsider = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($viewer, ['role' => TeamRole::Member->value]);

    $this->actingAs($viewer)
        ->get(route('teams.members.show', [$team, $outsider]))
        ->assertNotFound();
});

test('a user who is not a team member cannot view profiles in that team', function () {
    $outsider = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($outsider)
        ->get(route('teams.members.show', [$team, $member]))
        ->assertForbidden();
});

test('guests cannot view member profiles', function () {
    $member = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->get(route('teams.members.show', [$team, $member]))
        ->assertRedirect(route('login'));
});
