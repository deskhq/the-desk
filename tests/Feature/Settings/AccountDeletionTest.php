<?php

use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;

/**
 * Attach the user to a fresh shared (non-personal) team with the given role.
 */
function attachToSharedTeam(User $user, TeamRole $role, ?Team $team = null): Team
{
    $team ??= Team::factory()->create();

    $team->members()->attach($user, ['role' => $role->value]);

    return $team;
}

test('authored messages are reassigned to the deleted-user tombstone', function () {
    $user = User::factory()->create();
    $channel = Channel::factory()->create();
    $message = Message::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $user->id,
    ]);

    $this->actingAs($user)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/');

    $tombstone = User::where('is_tombstone', true)->firstOrFail();

    expect($user->fresh())->toBeNull();
    expect(Message::find($message->id)->user_id)->toBe($tombstone->id);
    expect($tombstone->name)->toBe('Deleted User');
});

test('the personal team is soft-deleted with the account', function () {
    $user = User::factory()->create();
    $personalTeam = $user->personalTeam();

    $this->actingAs($user)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertSessionHasNoErrors();

    expect(Team::find($personalTeam->id))->toBeNull();
    expect(Team::withTrashed()->find($personalTeam->id)->trashed())->toBeTrue();
});

test('the sole owner of a shared team cannot delete their account', function () {
    $user = User::factory()->create();
    $team = attachToSharedTeam($user, TeamRole::Owner);

    $this->actingAs($user)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertSessionHasErrors('team')
        ->assertRedirect(route('profile.edit'));

    expect($user->fresh())->not->toBeNull();
    expect(Team::find($team->id))->not->toBeNull();
});

test('a blocked sole owner can transfer ownership then delete their account', function () {
    $user = User::factory()->create();
    $member = User::factory()->create();
    $team = attachToSharedTeam($user, TeamRole::Owner);
    attachToSharedTeam($member, TeamRole::Member, $team);

    $this->actingAs($user)
        ->post(route('teams.members.transfer-ownership', [$team, $member]), ['password' => 'password'])
        ->assertSessionHasNoErrors();

    $this->actingAs($user)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/');

    expect($user->fresh())->toBeNull();
    expect($team->fresh()->owner()->is($member))->toBeTrue();
});

test('a co-owned shared team does not block deletion', function () {
    $user = User::factory()->create();
    $coOwner = User::factory()->create();
    $team = attachToSharedTeam($user, TeamRole::Owner);
    attachToSharedTeam($coOwner, TeamRole::Owner, $team);

    $this->actingAs($user)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertSessionHasNoErrors();

    expect($user->fresh())->toBeNull();
    expect(Team::find($team->id))->not->toBeNull();
    expect($team->fresh()->owner()->is($coOwner))->toBeTrue();
});

test('membership of a shared team is dropped when the account is deleted', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = attachToSharedTeam($owner, TeamRole::Owner);
    attachToSharedTeam($member, TeamRole::Member, $team);

    $this->actingAs($member)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertSessionHasNoErrors();

    expect($member->fresh())->toBeNull();
    expect(Team::find($team->id))->not->toBeNull();
    expect($team->fresh()->members()->whereKey($member->id)->exists())->toBeFalse();
});

test('the deleted-user tombstone is a reused singleton', function () {
    $first = User::tombstone();
    $second = User::tombstone();

    expect($second->id)->toBe($first->id);
    expect($first->name)->toBe('Deleted User');
    expect($first->is_tombstone)->toBeTrue();
});
