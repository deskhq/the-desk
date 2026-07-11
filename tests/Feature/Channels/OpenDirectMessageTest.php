<?php

use App\Actions\Teams\CreateTeam;
use App\Enums\ChannelType;
use App\Enums\ChannelVisibility;
use App\Enums\NotificationLevel;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\Team;
use App\Models\User;

/**
 * Add a user to the team as a plain member and return them.
 */
function dmTeamMember(Team $team): User
{
    $user = User::factory()->create();
    $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Member]);

    return $user;
}

test('opening a direct message creates a private direct channel with both members', function () {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $other = dmTeamMember($team);

    $this->actingAs($owner)
        ->post(route('channels.dm.store', ['team' => $team->slug]), ['user_id' => $other->id])
        ->assertRedirect();

    $dm = Channel::where('team_id', $team->id)->where('type', ChannelType::Direct)->firstOrFail();

    expect($dm->type)->toBe(ChannelType::Direct)
        ->and($dm->visibility)->toBe(ChannelVisibility::Private)
        ->and($dm->name)->toBeNull()
        ->and($dm->slug)->toStartWith('dm-')
        ->and($dm->dm_key)->toBe(collect([$owner->id, $other->id])->sort()->implode(':'))
        ->and($dm->members()->whereKey($owner->id)->exists())->toBeTrue()
        ->and($dm->members()->whereKey($other->id)->exists())->toBeTrue()
        ->and($dm->channelMembers()->where('user_id', $owner->id)->value('notification_level'))
        ->toBe(NotificationLevel::All);
});

test('opening a direct message redirects to the channel via its synthetic slug', function () {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $other = dmTeamMember($team);

    $response = $this->actingAs($owner)
        ->post(route('channels.dm.store', ['team' => $team->slug]), ['user_id' => $other->id]);

    $dm = Channel::where('team_id', $team->id)->where('type', ChannelType::Direct)->firstOrFail();

    $response->assertRedirect(route('channels.show', ['team' => $team->slug, 'channel' => $dm->slug]));
});

test('opening a direct message twice in either direction yields the same channel', function () {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $other = dmTeamMember($team);

    $this->actingAs($owner)
        ->post(route('channels.dm.store', ['team' => $team->slug]), ['user_id' => $other->id]);
    $this->actingAs($other)
        ->post(route('channels.dm.store', ['team' => $team->slug]), ['user_id' => $owner->id]);

    expect(Channel::where('team_id', $team->id)->where('type', ChannelType::Direct)->count())->toBe(1);
});

test('a user can open a self direct message rendering a single member', function () {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');

    $this->actingAs($owner)
        ->post(route('channels.dm.store', ['team' => $team->slug]), ['user_id' => $owner->id])
        ->assertRedirect();

    $dm = Channel::where('team_id', $team->id)->where('type', ChannelType::Direct)->firstOrFail();

    expect($dm->dm_key)->toBe($owner->id)
        ->and($dm->members()->count())->toBe(1)
        ->and($dm->members()->whereKey($owner->id)->exists())->toBeTrue();
});

test('opening a self direct message twice yields the same channel', function () {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');

    $this->actingAs($owner)->post(route('channels.dm.store', ['team' => $team->slug]), ['user_id' => $owner->id]);
    $this->actingAs($owner)->post(route('channels.dm.store', ['team' => $team->slug]), ['user_id' => $owner->id]);

    expect(Channel::where('team_id', $team->id)->where('type', ChannelType::Direct)->count())->toBe(1);
});

test('a direct message cannot be opened with a non-team-member', function () {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $outsider = User::factory()->create();

    $this->actingAs($owner)
        ->post(route('channels.dm.store', ['team' => $team->slug]), ['user_id' => $outsider->id])
        ->assertSessionHasErrors('user_id');

    expect(Channel::where('team_id', $team->id)->where('type', ChannelType::Direct)->exists())->toBeFalse();
});

test('opening a direct message requires an existing user', function () {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');

    $this->actingAs($owner)
        ->post(route('channels.dm.store', ['team' => $team->slug]), ['user_id' => 'not-a-user'])
        ->assertSessionHasErrors('user_id');
});

test('a non-team-member cannot open a direct message in the team', function () {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->post(route('channels.dm.store', ['team' => $team->slug]), ['user_id' => $owner->id])
        ->assertForbidden();
});

test('a direct channel resolves through the existing channel show route', function () {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $other = dmTeamMember($team);

    $this->actingAs($owner)->post(route('channels.dm.store', ['team' => $team->slug]), ['user_id' => $other->id]);
    $dm = Channel::where('team_id', $team->id)->where('type', ChannelType::Direct)->firstOrFail();

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $dm->slug]))
        ->assertOk();
});

test('a team member who is not a participant cannot view a direct message', function () {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $other = dmTeamMember($team);
    $bystander = dmTeamMember($team);

    $this->actingAs($owner)->post(route('channels.dm.store', ['team' => $team->slug]), ['user_id' => $other->id]);
    $dm = Channel::where('team_id', $team->id)->where('type', ChannelType::Direct)->firstOrFail();

    $this->actingAs($bystander)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $dm->slug]))
        ->assertForbidden();
});
