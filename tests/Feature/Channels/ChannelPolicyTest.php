<?php

use App\Actions\Teams\CreateTeam;
use App\Enums\ChannelCreationPolicy;
use App\Enums\ChannelVisibility;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\User;

test('the #general channel cannot be archived by anyone', function (): void {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();

    expect($owner->can('archive', $general))->toBeFalse();
});

test('the channel creator can archive a regular channel', function (): void {
    $creator = User::factory()->create();
    $team = app(CreateTeam::class)->handle($creator, 'Acme');
    $channel = Channel::factory()->for($team)->create([
        'created_by' => $creator->id,
        'visibility' => 'public',
    ]);

    expect($creator->can('archive', $channel))->toBeTrue();
});

test('a plain member who did not create a channel cannot archive it', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);

    $channel = Channel::factory()->for($team)->create([
        'created_by' => $owner->id,
        'visibility' => 'public',
    ]);

    expect($member->can('archive', $channel))->toBeFalse();
});

test('the #general channel cannot be deleted by anyone', function (): void {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();

    expect($owner->can('delete', $general))->toBeFalse();
});

test('a team admin can delete a regular channel they did not create', function (): void {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $team->memberships()->create(['user_id' => $admin->id, 'role' => TeamRole::Admin]);

    $channel = Channel::factory()->for($team)->create(['created_by' => $owner->id]);

    expect($admin->can('delete', $channel))->toBeTrue();
});

test('an admin can restore a deleted channel but has nothing to restore on a live one', function (): void {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $team->memberships()->create(['user_id' => $admin->id, 'role' => TeamRole::Admin]);

    $channel = Channel::factory()->for($team)->create();

    expect($admin->can('restore', $channel))->toBeFalse();

    $channel->delete();

    expect($admin->can('restore', $channel))->toBeTrue();
});

test('a plain member cannot restore a deleted channel', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);

    $channel = Channel::factory()->for($team)->create();
    $channel->delete();

    expect($member->can('restore', $channel))->toBeFalse();
});

test('a user who is not a team member cannot archive a channel', function (): void {
    $owner = User::factory()->create();
    $outsider = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $channel = Channel::factory()->for($team)->create(['created_by' => $owner->id]);

    expect($outsider->can('archive', $channel))->toBeFalse();
});

test('an already-archived channel cannot be archived again', function (): void {
    $creator = User::factory()->create();
    $team = app(CreateTeam::class)->handle($creator, 'Acme');
    $channel = Channel::factory()->for($team)->archived()->create(['created_by' => $creator->id]);

    expect($creator->can('archive', $channel))->toBeFalse();
});

test('a team member can view a public channel but a non-member cannot', function (): void {
    $owner = User::factory()->create();
    $outsider = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();

    expect($owner->can('view', $general))->toBeTrue()
        ->and($outsider->can('view', $general))->toBeFalse();
});

test('a private channel is only viewable by its members', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);

    $private = Channel::factory()->for($team)->private()->create(['created_by' => $owner->id]);
    $private->channelMembers()->create(['user_id' => $owner->id]);

    expect($owner->can('view', $private))->toBeTrue()
        ->and($member->can('view', $private))->toBeFalse();
});

test('any team member can create a channel but an outsider cannot', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $outsider = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);

    expect($member->can('create', [Channel::class, $team]))->toBeTrue()
        ->and($outsider->can('create', [Channel::class, $team]))->toBeFalse();
});

test('a public channel is joinable by a team member but a private one is not', function (): void {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $public = Channel::factory()->for($team)->create();
    $private = Channel::factory()->for($team)->private()->create();

    expect($owner->can('join', $public))->toBeTrue()
        ->and($owner->can('join', $private))->toBeFalse();
});

test('an archived public channel is not joinable', function (): void {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $archived = Channel::factory()->for($team)->archived()->create();

    expect($owner->can('join', $archived))->toBeFalse();
});

test('a private channel member or admin may manage its membership', function (): void {
    $owner = User::factory()->create();
    $channelMember = User::factory()->create();
    $admin = User::factory()->create();
    $plainMember = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $team->memberships()->create(['user_id' => $channelMember->id, 'role' => TeamRole::Member]);
    $team->memberships()->create(['user_id' => $admin->id, 'role' => TeamRole::Admin]);
    $team->memberships()->create(['user_id' => $plainMember->id, 'role' => TeamRole::Member]);

    $private = Channel::factory()->for($team)->private()->create();
    $private->channelMembers()->create(['user_id' => $channelMember->id]);

    expect($channelMember->can('addMember', $private))->toBeTrue()
        ->and($admin->can('addMember', $private))->toBeTrue()
        ->and($plainMember->can('addMember', $private))->toBeFalse()
        ->and($channelMember->can('removeMember', $private))->toBeTrue()
        ->and($plainMember->can('removeMember', $private))->toBeFalse();
});

test('only channel members may post a message', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);

    $channel = Channel::factory()->for($team)->create();
    $channel->channelMembers()->create(['user_id' => $owner->id]);

    // $owner is a channel member; $member belongs to the team but not the channel.
    expect($owner->can('postMessage', $channel))->toBeTrue()
        ->and($member->can('postMessage', $channel))->toBeFalse();
});

test('a channel member cannot post to an archived channel', function (): void {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $channel = Channel::factory()->for($team)->archived()->create();
    $channel->channelMembers()->create(['user_id' => $owner->id]);

    expect($owner->can('postMessage', $channel))->toBeFalse();
});

test('membership cannot be managed on a public channel', function (): void {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $public = Channel::factory()->for($team)->create();
    $public->channelMembers()->create(['user_id' => $owner->id]);

    expect($owner->can('addMember', $public))->toBeFalse()
        ->and($owner->can('removeMember', $public))->toBeFalse();
});

test('a non-team-member cannot manage a private channel membership', function (): void {
    $owner = User::factory()->create();
    $outsider = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $private = Channel::factory()->for($team)->private()->create();

    expect($outsider->can('addMember', $private))->toBeFalse()
        ->and($outsider->can('removeMember', $private))->toBeFalse();
});

test('changing your own membership is granted only to channel members', function (): void {
    $channelMember = User::factory()->create();
    $team = app(CreateTeam::class)->handle($channelMember, 'Acme');
    $channel = Channel::factory()->for($team)->create();
    $channel->channelMembers()->create(['user_id' => $channelMember->id]);

    // A team member who never joined this channel — the team branch of the
    // shared isMember predicate is true, the membership branch is false.
    $teamMember = User::factory()->create();
    $team->memberships()->create(['user_id' => $teamMember->id, 'role' => TeamRole::Member]);

    // An outsider who does not belong to the team at all — the team branch is
    // false, so the predicate short-circuits before touching membership.
    $outsider = User::factory()->create();

    expect($channelMember->can('updateMembership', $channel))->toBeTrue()
        ->and($teamMember->can('updateMembership', $channel))->toBeFalse()
        ->and($outsider->can('updateMembership', $channel))->toBeFalse();
});

test('any team member may create either kind of channel while both policies stay open', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);

    expect($member->can('create', [Channel::class, $team, ChannelVisibility::Public]))->toBeTrue()
        ->and($member->can('create', [Channel::class, $team, ChannelVisibility::Private]))->toBeTrue();
});

test('reserving public channels for admins leaves private channels open to members', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);
    $team->update(['public_channel_creation_policy' => ChannelCreationPolicy::Admins]);

    expect($member->can('create', [Channel::class, $team, ChannelVisibility::Public]))->toBeFalse()
        ->and($member->can('create', [Channel::class, $team, ChannelVisibility::Private]))->toBeTrue()
        ->and($owner->can('create', [Channel::class, $team, ChannelVisibility::Public]))->toBeTrue();
});

test('reserving private channels for admins leaves public channels open to members', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);
    $team->update(['private_channel_creation_policy' => ChannelCreationPolicy::Admins]);

    expect($member->can('create', [Channel::class, $team, ChannelVisibility::Private]))->toBeFalse()
        ->and($member->can('create', [Channel::class, $team, ChannelVisibility::Public]))->toBeTrue()
        ->and($owner->can('create', [Channel::class, $team, ChannelVisibility::Private]))->toBeTrue();
});

test('an outsider can never create a channel however open the policies are', function (): void {
    $owner = User::factory()->create();
    $outsider = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');

    expect($outsider->can('create', [Channel::class, $team, ChannelVisibility::Public]))->toBeFalse()
        ->and($outsider->can('create', [Channel::class, $team]))->toBeFalse();
});

test('asking without a visibility answers whether either kind of channel is creatable', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);

    expect($member->can('create', [Channel::class, $team]))->toBeTrue();

    $team->update(['public_channel_creation_policy' => ChannelCreationPolicy::Admins]);
    expect($member->can('create', [Channel::class, $team]))->toBeTrue();

    $team->update(['private_channel_creation_policy' => ChannelCreationPolicy::Admins]);
    expect($member->can('create', [Channel::class, $team]))->toBeFalse()
        ->and($owner->can('create', [Channel::class, $team]))->toBeTrue();
});
