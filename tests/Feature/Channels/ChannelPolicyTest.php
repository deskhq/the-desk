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
