<?php

declare(strict_types=1);

use App\Actions\Teams\CreateTeam;
use App\Enums\TeamRole;
use App\Http\Requests\Teams\StoreUserGroupRequest;
use App\Models\Team;
use App\Models\User;
use App\Models\UserGroup;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A workspace with its owner.
 *
 * @return array{0: User, 1: Team}
 */
function userGroupTeam(): array
{
    $owner = User::factory()->create();

    return [$owner, app(CreateTeam::class)->handle($owner, 'Acme')];
}

/**
 * Add a user to the team in the given role and return them.
 */
function userGroupMember(Team $team, TeamRole $role = TeamRole::Member, ?string $name = null): User
{
    $user = User::factory()->create($name ? ['name' => $name] : []);
    $team->memberships()->create(['user_id' => $user->id, 'role' => $role]);

    return $user;
}

test('an owner creates a group and the handle is derived from the name', function (): void {
    [$owner, $team] = userGroupTeam();

    $this->actingAs($owner)
        ->post(route('teams.groups.store', $team), ['name' => 'Dev Team'])
        ->assertRedirect();

    $group = UserGroup::sole();

    expect($group->name)->toBe('Dev Team')
        ->and($group->slug)->toBe('dev-team')
        ->and($group->team_id)->toBe($team->id)
        ->and($group->members)->toHaveCount(0);
});

test('an explicit handle overrides the derived one', function (): void {
    [$owner, $team] = userGroupTeam();

    $this->actingAs($owner)
        ->post(route('teams.groups.store', $team), ['name' => 'Dev Team', 'slug' => 'devs'])
        ->assertRedirect();

    expect(UserGroup::sole()->slug)->toBe('devs');
});

test('a handle must be unique within the workspace but may repeat across workspaces', function (): void {
    [$owner, $team] = userGroupTeam();
    UserGroup::factory()->for($team)->slug('dev-team')->create();

    $this->actingAs($owner)
        ->post(route('teams.groups.store', $team), ['name' => 'Dev Team'])
        ->assertSessionHasErrors('slug');

    $otherOwner = User::factory()->create();
    $otherTeam = app(CreateTeam::class)->handle($otherOwner, 'Globex');

    $this->actingAs($otherOwner)
        ->post(route('teams.groups.store', $otherTeam), ['name' => 'Dev Team'])
        ->assertSessionHasNoErrors();

    expect(UserGroup::where('slug', 'dev-team')->count())->toBe(2);
});

test('a handle that is not lowercase kebab-case is rejected', function (string $slug): void {
    [$owner, $team] = userGroupTeam();

    $this->actingAs($owner)
        ->post(route('teams.groups.store', $team), ['name' => 'Dev Team', 'slug' => $slug])
        ->assertSessionHasErrors('slug');
})->with(['Dev Team', 'dev_team', 'DevTeam', '-devs', 'devs-', 'dev--team', 'dév']);

test('a name that slugs to nothing still derives a usable handle', function (string $name): void {
    [$owner, $team] = userGroupTeam();

    $this->actingAs($owner)
        ->post(route('teams.groups.store', $team), ['name' => $name])
        ->assertSessionHasNoErrors();

    expect(UserGroup::sole()->slug)->toMatch(StoreUserGroupRequest::SLUG_PATTERN);
})->with([
    'japanese' => ['日本語'],
    'korean' => ['한국어'],
    'hebrew' => ['עברית'],
    'punctuation' => ['!!!'],
    'emoji' => ['🎉🎉'],
]);

test('two groups whose names slug to nothing get distinct handles', function (): void {
    [$owner, $team] = userGroupTeam();

    foreach (['日本語', '中文'] as $name) {
        $this->actingAs($owner)
            ->post(route('teams.groups.store', $team), ['name' => $name])
            ->assertSessionHasNoErrors();
    }

    expect(UserGroup::pluck('slug')->unique())->toHaveCount(2);
});

test('renaming a group to a name that slugs to nothing keeps a usable handle', function (): void {
    [$owner, $team] = userGroupTeam();
    $group = UserGroup::factory()->for($team)->slug('dev-team')->create();

    $this->actingAs($owner)
        ->patch(route('teams.groups.update', [$team, $group]), ['name' => '日本語'])
        ->assertSessionHasNoErrors();

    expect($group->fresh()->slug)->toMatch(StoreUserGroupRequest::SLUG_PATTERN);
});

test('an admin manages groups but a plain member may not', function (): void {
    [, $team] = userGroupTeam();
    $admin = userGroupMember($team, TeamRole::Admin);
    $member = userGroupMember($team);
    $group = UserGroup::factory()->for($team)->slug('dev-team')->create();

    $this->actingAs($admin)->get(route('teams.groups.index', $team))->assertOk();
    $this->actingAs($member)->get(route('teams.groups.index', $team))->assertForbidden();

    $this->actingAs($member)
        ->post(route('teams.groups.store', $team), ['name' => 'Ops'])
        ->assertForbidden();

    $this->actingAs($member)
        ->patch(route('teams.groups.update', [$team, $group]), ['name' => 'Ops'])
        ->assertForbidden();

    $this->actingAs($member)
        ->delete(route('teams.groups.destroy', [$team, $group]))
        ->assertForbidden();
});

test('a personal workspace has no groups to manage', function (): void {
    $user = User::factory()->create();
    $personal = $user->personalTeam();

    $this->actingAs($user)->get(route('teams.groups.index', $personal))->assertForbidden();
    $this->actingAs($user)
        ->post(route('teams.groups.store', $personal), ['name' => 'Solo'])
        ->assertForbidden();
});

test('the index page lists the workspace groups with their members', function (): void {
    [$owner, $team] = userGroupTeam();
    $ada = userGroupMember($team, TeamRole::Member, 'Ada Lovelace');
    $group = UserGroup::factory()->for($team)->slug('dev-team')->create();
    $group->members()->attach($ada->id);

    $this->actingAs($owner)
        ->get(route('teams.groups.index', $team))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('teams/Groups')
            ->has('groups', 1)
            ->where('groups.0.slug', 'dev-team')
            ->where('groups.0.membersCount', 1)
            ->where('groups.0.members.0.name', 'Ada Lovelace')
            ->has('members', 2)
            ->where('permissions.canManageUserGroups', true)
        );
});

test('a group is renamed and its handle updated', function (): void {
    [$owner, $team] = userGroupTeam();
    $group = UserGroup::factory()->for($team)->slug('dev-team')->create();

    $this->actingAs($owner)
        ->patch(route('teams.groups.update', [$team, $group]), ['name' => 'Platform', 'slug' => 'platform'])
        ->assertRedirect();

    expect($group->refresh()->name)->toBe('Platform')
        ->and($group->slug)->toBe('platform');
});

test('renaming a group may keep its own handle', function (): void {
    [$owner, $team] = userGroupTeam();
    $group = UserGroup::factory()->for($team)->slug('dev-team')->create();

    $this->actingAs($owner)
        ->patch(route('teams.groups.update', [$team, $group]), ['name' => 'Dev Squad', 'slug' => 'dev-team'])
        ->assertSessionHasNoErrors();

    expect($group->refresh()->name)->toBe('Dev Squad');
});

test('a group is deleted along with its membership rows', function (): void {
    [$owner, $team] = userGroupTeam();
    $ada = userGroupMember($team);
    $group = UserGroup::factory()->for($team)->slug('dev-team')->create();
    $group->members()->attach($ada->id);

    $this->actingAs($owner)
        ->delete(route('teams.groups.destroy', [$team, $group]))
        ->assertRedirect();

    $this->assertDatabaseMissing('user_groups', ['id' => $group->id]);
    $this->assertDatabaseMissing('user_group_user', ['user_group_id' => $group->id]);
});

test('a group from another workspace is not reachable through this one', function (): void {
    [$owner, $team] = userGroupTeam();
    $otherOwner = User::factory()->create();
    $otherTeam = app(CreateTeam::class)->handle($otherOwner, 'Globex');
    $foreign = UserGroup::factory()->for($otherTeam)->slug('dev-team')->create();

    $this->actingAs($owner)
        ->patch(route('teams.groups.update', [$team, $foreign]), ['name' => 'Hijacked'])
        ->assertNotFound();

    $this->actingAs($owner)
        ->delete(route('teams.groups.destroy', [$team, $foreign]))
        ->assertNotFound();
});

test('a team member is added to and removed from a group', function (): void {
    [$owner, $team] = userGroupTeam();
    $ada = userGroupMember($team);
    $group = UserGroup::factory()->for($team)->slug('dev-team')->create();

    $this->actingAs($owner)
        ->post(route('teams.groups.members.store', [$team, $group]), ['user_id' => $ada->id])
        ->assertRedirect();

    expect($group->members()->pluck('users.id')->all())->toBe([$ada->id]);

    $this->actingAs($owner)
        ->delete(route('teams.groups.members.destroy', [$team, $group, $ada]))
        ->assertRedirect();

    expect($group->members()->count())->toBe(0);
});

test('adding the same member twice leaves a single membership row', function (): void {
    [$owner, $team] = userGroupTeam();
    $ada = userGroupMember($team);
    $group = UserGroup::factory()->for($team)->slug('dev-team')->create();

    $this->actingAs($owner)
        ->post(route('teams.groups.members.store', [$team, $group]), ['user_id' => $ada->id]);
    $this->actingAs($owner)
        ->post(route('teams.groups.members.store', [$team, $group]), ['user_id' => $ada->id])
        ->assertRedirect();

    expect($group->members()->count())->toBe(1);
});

test('someone outside the workspace cannot be added to one of its groups', function (): void {
    [$owner, $team] = userGroupTeam();
    $stranger = User::factory()->create();
    $group = UserGroup::factory()->for($team)->slug('dev-team')->create();

    $this->actingAs($owner)
        ->post(route('teams.groups.members.store', [$team, $group]), ['user_id' => $stranger->id])
        ->assertSessionHasErrors('user_id');
});

test('a plain member cannot edit group membership', function (): void {
    [, $team] = userGroupTeam();
    $member = userGroupMember($team);
    $ada = userGroupMember($team);
    $group = UserGroup::factory()->for($team)->slug('dev-team')->create();
    $group->members()->attach($ada->id);

    $this->actingAs($member)
        ->post(route('teams.groups.members.store', [$team, $group]), ['user_id' => $member->id])
        ->assertForbidden();

    $this->actingAs($member)
        ->delete(route('teams.groups.members.destroy', [$team, $group, $ada]))
        ->assertForbidden();
});

test('removing someone from the workspace drops them from its groups', function (): void {
    [$owner, $team] = userGroupTeam();
    $ada = userGroupMember($team);
    $group = UserGroup::factory()->for($team)->slug('dev-team')->create();
    $group->members()->attach($ada->id);

    $this->actingAs($owner)
        ->delete(route('teams.members.destroy', ['team' => $team->slug, 'member' => $ada->id]))
        ->assertRedirect();

    expect($group->members()->count())->toBe(0);
});

test('leaving the workspace drops you from its groups', function (): void {
    [, $team] = userGroupTeam();
    $ada = userGroupMember($team);
    $group = UserGroup::factory()->for($team)->slug('dev-team')->create();
    $group->members()->attach($ada->id);

    $this->actingAs($ada)
        ->delete(route('teams.leave', $team))
        ->assertRedirect();

    expect($group->members()->count())->toBe(0);
});

test('a group cannot be saved with a blank handle', function (?string $slug): void {
    $group = UserGroup::factory()->create(['name' => '日本語', 'slug' => $slug]);

    expect($group->fresh()->slug)->toMatch(StoreUserGroupRequest::SLUG_PATTERN);
})->with([
    'empty' => [''],
    'whitespace' => ['   '],
    'null' => [null],
]);
