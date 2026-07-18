<?php

use App\Actions\Channels\JoinChannel;
use App\Actions\Teams\CreateTeam;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\Team;
use App\Models\User;

/**
 * Create a team with an extra member, returning [owner, member, team].
 *
 * @return array{0: User, 1: User, 2: Team}
 */
function teamWithMember(TeamRole $role = TeamRole::Member): array
{
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $team->memberships()->create(['user_id' => $member->id, 'role' => $role]);

    return [$owner, $member, $team];
}

test('a private channel member can add another team member', function (): void {
    [$owner, $member, $team] = teamWithMember();
    $channel = Channel::factory()->for($team)->private()->create(['slug' => 'secret']);
    app(JoinChannel::class)->handle($channel, $owner);

    $this->actingAs($owner)
        ->post(route('channels.members.store', ['team' => $team->slug, 'channel' => $channel->slug]), [
            'user_id' => $member->id,
        ])
        ->assertRedirect();

    expect($channel->members()->whereKey($member->id)->exists())->toBeTrue();
});

test('a team admin who is not a channel member can add a member to a private channel', function (): void {
    [$owner, $admin, $team] = teamWithMember(TeamRole::Admin);
    $target = User::factory()->create();
    $team->memberships()->create(['user_id' => $target->id, 'role' => TeamRole::Member]);
    $channel = Channel::factory()->for($team)->private()->create(['slug' => 'secret']);

    $this->actingAs($admin)
        ->post(route('channels.members.store', ['team' => $team->slug, 'channel' => $channel->slug]), [
            'user_id' => $target->id,
        ])
        ->assertRedirect();

    expect($channel->members()->whereKey($target->id)->exists())->toBeTrue();
});

test('a plain member who is not in the private channel cannot add members', function (): void {
    [$owner, $member, $team] = teamWithMember();
    $target = User::factory()->create();
    $team->memberships()->create(['user_id' => $target->id, 'role' => TeamRole::Member]);
    $channel = Channel::factory()->for($team)->private()->create(['slug' => 'secret']);
    app(JoinChannel::class)->handle($channel, $owner);

    $this->actingAs($member)
        ->post(route('channels.members.store', ['team' => $team->slug, 'channel' => $channel->slug]), [
            'user_id' => $target->id,
        ])
        ->assertForbidden();

    expect($channel->members()->whereKey($target->id)->exists())->toBeFalse();
});

test('members cannot be managed on a public channel through the members endpoint', function (): void {
    [$owner, $member, $team] = teamWithMember();
    $channel = Channel::factory()->for($team)->create(['slug' => 'marketing']);
    app(JoinChannel::class)->handle($channel, $owner);

    $this->actingAs($owner)
        ->post(route('channels.members.store', ['team' => $team->slug, 'channel' => $channel->slug]), [
            'user_id' => $member->id,
        ])
        ->assertForbidden();
});

test('a team-owned bot can be added to a private channel', function (): void {
    [$owner, $member, $team] = teamWithMember();
    $bot = User::factory()->bot($team)->create(['name' => 'Deploy Bot']);
    $channel = Channel::factory()->for($team)->private()->create(['slug' => 'secret']);
    app(JoinChannel::class)->handle($channel, $owner);

    $this->actingAs($owner)
        ->post(route('channels.members.store', ['team' => $team->slug, 'channel' => $channel->slug]), [
            'user_id' => $bot->id,
        ])
        ->assertRedirect();

    expect($channel->members()->whereKey($bot->id)->exists())->toBeTrue();
});

test('a bot owned by another team cannot be added to a channel', function (): void {
    [$owner, $member, $team] = teamWithMember();
    $otherTeam = app(CreateTeam::class)->handle(User::factory()->create(), 'Other');
    $foreignBot = User::factory()->bot($otherTeam)->create();
    $channel = Channel::factory()->for($team)->private()->create(['slug' => 'secret']);
    app(JoinChannel::class)->handle($channel, $owner);

    $this->actingAs($owner)
        ->post(route('channels.members.store', ['team' => $team->slug, 'channel' => $channel->slug]), [
            'user_id' => $foreignBot->id,
        ])
        ->assertSessionHasErrors('user_id');

    expect($channel->members()->whereKey($foreignBot->id)->exists())->toBeFalse();
});

test('a user who is not a team member cannot be added to a channel', function (): void {
    [$owner, $member, $team] = teamWithMember();
    $outsider = User::factory()->create();
    $channel = Channel::factory()->for($team)->private()->create(['slug' => 'secret']);
    app(JoinChannel::class)->handle($channel, $owner);

    $this->actingAs($owner)
        ->post(route('channels.members.store', ['team' => $team->slug, 'channel' => $channel->slug]), [
            'user_id' => $outsider->id,
        ])
        ->assertSessionHasErrors('user_id');

    expect($channel->members()->whereKey($outsider->id)->exists())->toBeFalse();
});

test('a private channel member can remove another member', function (): void {
    [$owner, $member, $team] = teamWithMember();
    $channel = Channel::factory()->for($team)->private()->create(['slug' => 'secret']);
    app(JoinChannel::class)->handle($channel, $owner);
    app(JoinChannel::class)->handle($channel, $member);

    $this->actingAs($owner)
        ->delete(route('channels.members.destroy', ['team' => $team->slug, 'channel' => $channel->slug]), [
            'user_id' => $member->id,
        ])
        ->assertRedirect();

    expect($channel->members()->whereKey($member->id)->exists())->toBeFalse();
});

test('a plain non-member cannot remove members from a private channel', function (): void {
    [$owner, $member, $team] = teamWithMember();
    $channel = Channel::factory()->for($team)->private()->create(['slug' => 'secret']);
    app(JoinChannel::class)->handle($channel, $owner);

    $this->actingAs($member)
        ->delete(route('channels.members.destroy', ['team' => $team->slug, 'channel' => $channel->slug]), [
            'user_id' => $owner->id,
        ])
        ->assertForbidden();

    expect($channel->members()->whereKey($owner->id)->exists())->toBeTrue();
});
