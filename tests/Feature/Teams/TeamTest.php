<?php

use App\Enums\AppLocale;
use App\Enums\SecurityEventType;
use App\Enums\TeamRole;
use App\Models\SecurityEvent;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the teams index page can be rendered', function (): void {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('teams.index'));

    $response->assertOk();
});

test('the teams index shows translated role labels for a french user', function (): void {
    $user = User::factory()->create(['locale' => AppLocale::French->value]);

    $this
        ->actingAs($user)
        ->get(route('teams.index'))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('teams.0.roleLabel', 'Propriétaire'));
});

test('teams can be created', function (): void {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->post(route('teams.store'), [
            'name' => 'Test Team',
        ]);

    $response->assertRedirect();

    $this->assertDatabaseHas('teams', [
        'name' => 'Test Team',
        'is_personal' => false,
    ]);
});

test('team slug uses next available suffix', function (): void {
    $user = User::factory()->create();

    Team::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
    Team::factory()->create(['name' => 'Acme One', 'slug' => 'acme-1']);
    Team::factory()->create(['name' => 'Acme Ten', 'slug' => 'acme-10']);

    $this
        ->actingAs($user)
        ->post(route('teams.store'), [
            'name' => 'Acme',
        ]);

    $this->assertDatabaseHas('teams', [
        'name' => 'Acme',
        'slug' => 'acme-11',
    ]);
});

test('the team edit page can be rendered', function (): void {
    $user = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $response = $this
        ->actingAs($user)
        ->get(route('teams.edit', $team));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('teams/Edit')
            ->where('team.role', TeamRole::Owner->value)
            ->where('members.0.role', TeamRole::Owner->value)
            ->where('members.0.role_label', TeamRole::Owner->label()),
        );
});

test('the team edit page orders the roster by role, then name, then id', function (): void {
    $team = Team::factory()->create();

    $owner = User::factory()->create(['name' => 'Zoe Owner']);
    $adminBob = User::factory()->create(['name' => 'Bob Admin']);
    $adminAlice = User::factory()->create(['name' => 'alice Admin']);
    $memberCarol = User::factory()->create(['name' => 'Carol Member']);
    $memberCarolTwin = User::factory()->create(['name' => 'Carol Member']);

    // Attached in an order that contradicts the expected one, so a roster that
    // merely echoes insertion order cannot pass by accident (issue #722).
    $team->members()->attach($memberCarolTwin, ['role' => TeamRole::Member->value]);
    $team->members()->attach($adminBob, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($memberCarol, ['role' => TeamRole::Member->value]);
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($adminAlice, ['role' => TeamRole::Admin->value]);

    $carolsById = collect([$memberCarol, $memberCarolTwin])->sortBy('id')->values();

    $response = $this
        ->actingAs($owner)
        ->get(route('teams.edit', $team));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('teams/Edit')
            ->where('members.0.id', $owner->id)
            ->where('members.1.id', $adminAlice->id)
            ->where('members.2.id', $adminBob->id)
            ->where('members.3.id', $carolsById[0]->id)
            ->where('members.4.id', $carolsById[1]->id)
        );
});

test('the team edit page orders every role by its hierarchy level', function (): void {
    $team = Team::factory()->create();

    $expected = collect(TeamRole::cases())
        ->sortByDesc(fn (TeamRole $role): int => $role->level())
        ->values();

    // Named in reverse of the expected order, so a roster that lost its role
    // ranking and fell back to the name tie-break would come out backwards.
    $membersByRole = [];

    foreach ($expected as $rank => $role) {
        $member = User::factory()->create(['name' => chr(ord('z') - $rank).' Member']);
        $team->members()->attach($member, ['role' => $role->value]);
        $membersByRole[$role->value] = $member;
    }

    $response = $this
        ->actingAs($membersByRole[TeamRole::Owner->value])
        ->get(route('teams.edit', $team));

    $response
        ->assertOk()
        ->assertInertia(function (Assert $page) use ($expected, $membersByRole): void {
            $page->component('teams/Edit')->has('members', $expected->count());

            foreach ($expected as $position => $role) {
                $page->where("members.{$position}.id", $membersByRole[$role->value]->id);
            }
        });
});

test('the team edit page cannot be viewed by non-members', function (): void {
    $owner = User::factory()->create();
    $outsider = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $response = $this
        ->actingAs($outsider)
        ->get(route('teams.edit', $team));

    $response->assertForbidden();
});

test('the team edit page hides member emails and invitations from plain members', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($member)
        ->get(route('teams.edit', $team));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('teams/Edit')
            ->where('members.0.email', null)
            ->where('members.1.email', null)
            ->where('invitations', []),
        );
});

test('the team edit page shows member emails and invitations to admins', function (): void {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($admin)
        ->get(route('teams.edit', $team));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('teams/Edit')
            ->has('members', 2)
            ->where('members.0.email', $owner->email)
            ->where('members.1.email', $admin->email)
            ->where('invitations.0.email', $invitation->email)
            ->where('invitations.0.role', $invitation->role->value),
        );
});

test('teams can be updated by owners', function (): void {
    $user = User::factory()->create();
    $team = Team::factory()->create(['name' => 'Original Name']);

    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $response = $this
        ->actingAs($user)
        ->patch(route('teams.update', $team), [
            'name' => 'Updated Name',
        ]);

    $response->assertRedirect(route('teams.edit', $team->fresh()));

    $this->assertDatabaseHas('teams', [
        'id' => $team->id,
        'name' => 'Updated Name',
    ]);
});

test('teams cannot be updated by members', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $response = $this
        ->actingAs($member)
        ->patch(route('teams.update', $team), [
            'name' => 'Updated Name',
        ]);

    $response->assertForbidden();
});

test('teams can be deleted by owners', function (): void {
    $user = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $response = $this
        ->actingAs($user)
        ->delete(route('teams.destroy', $team), [
            'name' => $team->name,
        ]);

    $response->assertRedirect();

    $this->assertSoftDeleted('teams', [
        'id' => $team->id,
    ]);

    expect(SecurityEvent::query()->where('user_id', $user->id)->where('type', SecurityEventType::TeamDeleted)->count())->toBe(1);
});

test('team deletion requires name confirmation', function (): void {
    $user = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $response = $this
        ->actingAs($user)
        ->delete(route('teams.destroy', $team), [
            'name' => 'Wrong Name',
        ]);

    $response->assertSessionHasErrors('name');

    $this->assertDatabaseHas('teams', [
        'id' => $team->id,
        'deleted_at' => null,
    ]);
});

test('deleting current team switches to alphabetically first remaining team', function (): void {
    $user = User::factory()->create(['name' => 'Mike']);

    $zuluTeam = Team::factory()->create(['name' => 'Zulu Team']);
    $zuluTeam->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $alphaTeam = Team::factory()->create(['name' => 'Alpha Team']);
    $alphaTeam->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $betaTeam = Team::factory()->create(['name' => 'Beta Team']);
    $betaTeam->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $user->update(['current_team_id' => $zuluTeam->id]);

    $response = $this
        ->actingAs($user)
        ->delete(route('teams.destroy', $zuluTeam), [
            'name' => $zuluTeam->name,
        ]);

    $response->assertRedirect();

    $this->assertSoftDeleted('teams', [
        'id' => $zuluTeam->id,
    ]);

    expect($user->fresh()->current_team_id)->toEqual($alphaTeam->id);
});

test('deleting current team falls back to personal team when alphabetically first', function (): void {
    $user = User::factory()->create();
    $personalTeam = $user->personalTeam();
    $team = Team::factory()->create(['name' => 'Zulu Team']);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $user->update(['current_team_id' => $team->id]);

    $response = $this
        ->actingAs($user)
        ->delete(route('teams.destroy', $team), [
            'name' => $team->name,
        ]);

    $response->assertRedirect();

    $this->assertSoftDeleted('teams', [
        'id' => $team->id,
    ]);

    expect($user->fresh()->current_team_id)->toEqual($personalTeam->id);
});

test('deleting non current team leaves current team unchanged', function (): void {
    $user = User::factory()->create();
    $personalTeam = $user->personalTeam();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $user->update(['current_team_id' => $personalTeam->id]);

    $response = $this
        ->actingAs($user)
        ->delete(route('teams.destroy', $team), [
            'name' => $team->name,
        ]);

    $response->assertRedirect();

    $this->assertSoftDeleted('teams', [
        'id' => $team->id,
    ]);

    expect($user->fresh()->current_team_id)->toEqual($personalTeam->id);
});

test('members can leave non personal teams', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $response = $this
        ->actingAs($member)
        ->delete(route('teams.leave', $team));

    $response->assertRedirect(route('teams.index'));
    $response->assertInertiaFlash('toast', ['type' => 'success', 'message' => "You left the team \"{$team->name}\""]);

    expect($member->fresh()->belongsToTeam($team))->toBeFalse();
});

test('leaving current team switches to alphabetically first remaining team', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create(['name' => 'Mike']);

    $zuluTeam = Team::factory()->create(['name' => 'Zulu Team']);
    $zuluTeam->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $zuluTeam->members()->attach($member, ['role' => TeamRole::Member->value]);

    $alphaTeam = Team::factory()->create(['name' => 'Alpha Team']);
    $alphaTeam->members()->attach($member, ['role' => TeamRole::Member->value]);

    $betaTeam = Team::factory()->create(['name' => 'Beta Team']);
    $betaTeam->members()->attach($member, ['role' => TeamRole::Member->value]);

    $member->update(['current_team_id' => $zuluTeam->id]);

    $response = $this
        ->actingAs($member)
        ->delete(route('teams.leave', $zuluTeam));

    $response->assertRedirect(route('teams.index'));

    expect($member->fresh()->belongsToTeam($zuluTeam))->toBeFalse();
    expect($member->fresh()->current_team_id)->toEqual($alphaTeam->id);
});

test('personal teams cannot be left', function (): void {
    $user = User::factory()->create();
    $personalTeam = $user->personalTeam();

    $response = $this
        ->actingAs($user)
        ->delete(route('teams.leave', $personalTeam));

    $response->assertForbidden();

    expect($user->fresh()->belongsToTeam($personalTeam))->toBeTrue();
});

test('team owners cannot leave their team', function (): void {
    $owner = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $response = $this
        ->actingAs($owner)
        ->delete(route('teams.leave', $team));

    $response->assertForbidden();

    expect($owner->fresh()->belongsToTeam($team))->toBeTrue();
});

test('users cannot leave teams they dont belong to', function (): void {
    $user = User::factory()->create();
    $team = Team::factory()->create();

    $response = $this
        ->actingAs($user)
        ->delete(route('teams.leave', $team));

    $response->assertForbidden();
});

test('deleting team switches other affected users to their personal team', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();

    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $owner->update(['current_team_id' => $team->id]);
    $member->update(['current_team_id' => $team->id]);

    $response = $this
        ->actingAs($owner)
        ->delete(route('teams.destroy', $team), [
            'name' => $team->name,
        ]);

    $response->assertRedirect();

    expect($member->fresh()->current_team_id)->toEqual($member->personalTeam()->id);
});

test('personal teams cannot be deleted', function (): void {
    $user = User::factory()->create();

    $personalTeam = $user->personalTeam();

    $response = $this
        ->actingAs($user)
        ->delete(route('teams.destroy', $personalTeam), [
            'name' => $personalTeam->name,
        ]);

    $response->assertForbidden();

    $this->assertDatabaseHas('teams', [
        'id' => $personalTeam->id,
        'deleted_at' => null,
    ]);
});

test('teams cannot be deleted by non owners', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $response = $this
        ->actingAs($member)
        ->delete(route('teams.destroy', $team), [
            'name' => $team->name,
        ]);

    $response->assertForbidden();
});

test('users can switch teams', function (): void {
    $user = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($user, ['role' => TeamRole::Member->value]);

    $response = $this
        ->actingAs($user)
        ->post(route('teams.switch', $team));

    $response->assertRedirect();

    expect($user->fresh()->current_team_id)->toEqual($team->id);
});

test('users cannot switch to team they dont belong to', function (): void {
    $user = User::factory()->create();
    $team = Team::factory()->create();

    $response = $this
        ->actingAs($user)
        ->post(route('teams.switch', $team));

    $response->assertForbidden();
});

test('guests cannot access teams', function (): void {
    $response = $this->get(route('teams.index'));

    $response->assertRedirect(route('login'));
});
