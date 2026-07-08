<?php

use App\Enums\TeamRole;
use App\Http\Middleware\EnsureTeamMembership;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * Register a throwaway route guarded by the middleware for the given minimum role
 * and return its URL for the given team slug.
 */
function gatedTeamUrl(string $slug, ?string $role = null): string
{
    $suffix = $role ?? 'any';
    $middleware = $role === null
        ? EnsureTeamMembership::class
        : EnsureTeamMembership::class.':'.$role;

    Route::middleware($middleware)
        ->get('_test/gated/'.$suffix.'/{current_team}', fn () => response('ok'));

    return '/_test/gated/'.$suffix.'/'.$slug;
}

test('members are allowed through', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(gatedTeamUrl($user->currentTeam->slug))
        ->assertOk();
});

test('non members are forbidden', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();

    $this->actingAs($user)
        ->get(gatedTeamUrl($team->slug))
        ->assertForbidden();
});

test('unknown team slug is forbidden', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(gatedTeamUrl('does-not-exist'))
        ->assertForbidden();
});

test('members with a sufficient role are allowed through', function () {
    $user = User::factory()->create();

    // Personal-team owners outrank the admin requirement.
    $this->actingAs($user)
        ->get(gatedTeamUrl($user->currentTeam->slug, TeamRole::Admin->value))
        ->assertOk();
});

test('members with an insufficient role are forbidden', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($member)
        ->get(gatedTeamUrl($team->slug, TeamRole::Admin->value))
        ->assertForbidden();
});

test('an unknown minimum role is forbidden', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(gatedTeamUrl($user->currentTeam->slug, 'superuser'))
        ->assertForbidden();
});

test('visiting a team route switches the current team', function () {
    $user = User::factory()->create();
    $current = $user->currentTeam;

    $other = Team::factory()->create();
    $other->members()->attach($user, ['role' => TeamRole::Owner->value]);

    expect($user->isCurrentTeam($current))->toBeTrue();

    $this->actingAs($user)
        ->get(route('dashboard', ['current_team' => $other->slug]))
        ->assertOk();

    expect($user->refresh()->current_team_id)->toBe($other->id);
});
