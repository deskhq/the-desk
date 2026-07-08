<?php

use App\Actions\Teams\CreateTeam;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\User;

test('the #general channel cannot be archived by anyone', function () {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();

    expect($owner->can('archive', $general))->toBeFalse();
});

test('the channel creator can archive a regular channel', function () {
    $creator = User::factory()->create();
    $team = app(CreateTeam::class)->handle($creator, 'Acme');
    $channel = Channel::factory()->for($team)->create([
        'created_by' => $creator->id,
        'visibility' => 'public',
    ]);

    expect($creator->can('archive', $channel))->toBeTrue();
});

test('a plain member who did not create a channel cannot archive it', function () {
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

test('the #general channel cannot be deleted by anyone', function () {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();

    expect($owner->can('delete', $general))->toBeFalse();
});

test('a team admin can delete a regular channel they did not create', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $team->memberships()->create(['user_id' => $admin->id, 'role' => TeamRole::Admin]);

    $channel = Channel::factory()->for($team)->create(['created_by' => $owner->id]);

    expect($admin->can('delete', $channel))->toBeTrue();
});

test('a user who is not a team member cannot archive a channel', function () {
    $owner = User::factory()->create();
    $outsider = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $channel = Channel::factory()->for($team)->create(['created_by' => $owner->id]);

    expect($outsider->can('archive', $channel))->toBeFalse();
});

test('an already-archived channel cannot be archived again', function () {
    $creator = User::factory()->create();
    $team = app(CreateTeam::class)->handle($creator, 'Acme');
    $channel = Channel::factory()->for($team)->archived()->create(['created_by' => $creator->id]);

    expect($creator->can('archive', $channel))->toBeFalse();
});

test('a team member can view a public channel but a non-member cannot', function () {
    $owner = User::factory()->create();
    $outsider = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();

    expect($owner->can('view', $general))->toBeTrue()
        ->and($outsider->can('view', $general))->toBeFalse();
});

test('a private channel is only viewable by its members', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);

    $private = Channel::factory()->for($team)->private()->create(['created_by' => $owner->id]);
    $private->channelMembers()->create(['user_id' => $owner->id]);

    expect($owner->can('view', $private))->toBeTrue()
        ->and($member->can('view', $private))->toBeFalse();
});

test('any team member can create a channel but an outsider cannot', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $outsider = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);

    expect($member->can('create', [Channel::class, $team]))->toBeTrue()
        ->and($outsider->can('create', [Channel::class, $team]))->toBeFalse();
});

test('a public channel is joinable by a team member but a private one is not', function () {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $public = Channel::factory()->for($team)->create();
    $private = Channel::factory()->for($team)->private()->create();

    expect($owner->can('join', $public))->toBeTrue()
        ->and($owner->can('join', $private))->toBeFalse();
});

test('an archived public channel is not joinable', function () {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $archived = Channel::factory()->for($team)->archived()->create();

    expect($owner->can('join', $archived))->toBeFalse();
});

test('a private channel member or admin may manage its membership', function () {
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

test('membership cannot be managed on a public channel', function () {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $public = Channel::factory()->for($team)->create();
    $public->channelMembers()->create(['user_id' => $owner->id]);

    expect($owner->can('addMember', $public))->toBeFalse()
        ->and($owner->can('removeMember', $public))->toBeFalse();
});
