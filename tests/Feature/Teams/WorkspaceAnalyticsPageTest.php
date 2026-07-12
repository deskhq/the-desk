<?php

use App\Actions\Teams\CreateTeam;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A team owned by a fresh user, with its #general channel.
 *
 * @return array{0: User, 1: Team}
 */
function analyticsPageTeam(): array
{
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');

    return [$owner, $team];
}

/**
 * Attach a member to a team with the given role.
 */
function analyticsPageMember(Team $team, TeamRole $role = TeamRole::Member): User
{
    $member = User::factory()->create();
    $team->members()->attach($member, ['role' => $role->value]);

    return $member;
}

test('an admin can view the analytics dashboard', function (): void {
    [$owner, $team] = analyticsPageTeam();
    $admin = analyticsPageMember($team, TeamRole::Admin);
    $general = $team->channels()->where('slug', Channel::GENERAL_SLUG)->firstOrFail();
    Message::factory()->for($general)->for($owner)->create();

    $this->actingAs($admin)
        ->get(route('teams.analytics.index', $team))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('teams/Analytics')
            ->where('range', '30d')
            ->where('analytics.messagesSent.value', 1)
            ->has('analytics.messagesByDay', 30)
            ->has('analytics.memberGrowth', 6)
            ->has('rangeOptions', 3)
        );
});

test('the owner can view the analytics dashboard', function (): void {
    [$owner, $team] = analyticsPageTeam();

    $this->actingAs($owner)
        ->get(route('teams.analytics.index', $team))
        ->assertOk();
});

test('a plain member cannot view the analytics dashboard', function (): void {
    [$owner, $team] = analyticsPageTeam();
    $member = analyticsPageMember($team);

    $this->actingAs($member)
        ->get(route('teams.analytics.index', $team))
        ->assertForbidden();
});

test('a non member cannot view the analytics dashboard', function (): void {
    [$owner, $team] = analyticsPageTeam();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->get(route('teams.analytics.index', $team))
        ->assertForbidden();
});

test('analytics is not available for a personal team', function (): void {
    $user = User::factory()->create();
    $personal = $user->personalTeam();

    $this->actingAs($user)
        ->get(route('teams.analytics.index', $personal))
        ->assertForbidden();
});

test('the requested range scopes the dashboard', function (): void {
    [$owner, $team] = analyticsPageTeam();

    $this->actingAs($owner)
        ->get(route('teams.analytics.index', [$team, 'range' => '7d']))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('range', '7d')
            ->where('analytics.range', '7d')
            ->has('analytics.messagesByDay', 7)
        );
});

test('an invalid range is rejected', function (): void {
    [$owner, $team] = analyticsPageTeam();

    $this->actingAs($owner)
        ->get(route('teams.analytics.index', [$team, 'range' => 'forever']))
        ->assertSessionHasErrors('range');
});
