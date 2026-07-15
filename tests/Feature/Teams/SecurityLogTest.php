<?php

use App\Actions\Teams\CreateTeam;
use App\Enums\SecurityEventType;
use App\Enums\TeamRole;
use App\Models\SecurityEvent;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Create a real (non-personal) team owned by a fresh user.
 *
 * @return array{0: User, 1: Team}
 */
function securityLogTeam(): array
{
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');

    return [$owner, $team];
}

/**
 * Attach a member to a team with the given role.
 */
function securityLogMember(Team $team, TeamRole $role = TeamRole::Member): User
{
    $member = User::factory()->create();
    $team->members()->attach($member, ['role' => $role->value]);

    return $member;
}

test('an owner can view the workspace security log', function (): void {
    [$owner, $team] = securityLogTeam();
    SecurityEvent::factory()->for($owner)->create();

    $this->actingAs($owner)
        ->get(route('teams.security-log.index', $team))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('teams/SecurityLog')
            ->has('events.data', 1));
});

test('an admin can view the workspace security log', function (): void {
    [, $team] = securityLogTeam();
    $admin = securityLogMember($team, TeamRole::Admin);
    SecurityEvent::factory()->for($admin)->create();

    $this->actingAs($admin)
        ->get(route('teams.security-log.index', $team))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('teams/SecurityLog')
            ->has('events.data', 1));
});

test('a plain member cannot view the workspace security log', function (): void {
    [, $team] = securityLogTeam();
    $member = securityLogMember($team, TeamRole::Member);

    $this->actingAs($member)
        ->get(route('teams.security-log.index', $team))
        ->assertForbidden();
});

test('a personal team exposes no security log', function (): void {
    $user = User::factory()->create();
    $personal = $user->personalTeam();

    $this->actingAs($user)
        ->get(route('teams.security-log.index', $personal))
        ->assertForbidden();
});

test('the security log shows current members events but not non-members', function (): void {
    [$owner, $team] = securityLogTeam();
    $member = securityLogMember($team, TeamRole::Member);
    $stranger = User::factory()->create();

    SecurityEvent::factory()->for($owner)->create();
    SecurityEvent::factory()->for($member)->create();
    SecurityEvent::factory()->for($stranger)->create();

    $this->actingAs($owner)
        ->get(route('teams.security-log.index', $team))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('teams/SecurityLog')
            ->has('events.data', 2));
});

test('the security log can be filtered by event type', function (): void {
    [$owner, $team] = securityLogTeam();
    SecurityEvent::factory()->for($owner)->ofType(SecurityEventType::LoggedIn)->create();
    SecurityEvent::factory()->for($owner)->ofType(SecurityEventType::PasswordChanged)->create();

    $this->actingAs($owner)
        ->get(route('teams.security-log.index', [$team, 'type' => SecurityEventType::PasswordChanged->value]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('events.data', 1)
            ->where('events.data.0.type', SecurityEventType::PasswordChanged->value)
            ->where('filters.type', SecurityEventType::PasswordChanged->value));
});

test('the security log can be filtered by actor', function (): void {
    [$owner, $team] = securityLogTeam();
    $member = securityLogMember($team, TeamRole::Member);
    SecurityEvent::factory()->for($owner)->create();
    SecurityEvent::factory()->for($member)->create();

    $this->actingAs($owner)
        ->get(route('teams.security-log.index', [$team, 'actor' => $member->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('events.data', 1)
            ->where('events.data.0.actorName', $member->name)
            ->where('filters.actor', $member->id));
});

test('the actor filter only lists current members with events', function (): void {
    [$owner, $team] = securityLogTeam();
    $member = securityLogMember($team, TeamRole::Member);
    securityLogMember($team, TeamRole::Member);
    $stranger = User::factory()->create();

    SecurityEvent::factory()->for($owner)->create();
    SecurityEvent::factory()->for($member)->create();
    SecurityEvent::factory()->for($stranger)->create();

    $this->actingAs($owner)
        ->get(route('teams.security-log.index', $team))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('actors', 2));
});

test('the security log paginates', function (): void {
    [$owner, $team] = securityLogTeam();
    SecurityEvent::factory()->for($owner)->count(35)->create();

    $this->actingAs($owner)
        ->get(route('teams.security-log.index', $team))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('events.data', 30)
            ->where('events.nextPageUrl', fn (?string $url): bool => $url !== null));
});
