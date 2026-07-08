<?php

use App\Actions\Teams\CreateTeam;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;

/**
 * Create a team, its #general channel, and a message authored in it by $author.
 *
 * @return array{0: User, 1: Team, 2: Channel, 3: Message}
 */
function teamWithMessage(User $author): array
{
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();
    $team->memberships()->firstOrCreate(['user_id' => $author->id], ['role' => TeamRole::Member]);
    $general->channelMembers()->firstOrCreate(['user_id' => $author->id]);
    $message = Message::factory()->for($general)->for($author)->create();

    return [$owner, $team, $general, $message];
}

test('the author can edit their own message', function () {
    $author = User::factory()->create();
    [, , , $message] = teamWithMessage($author);

    expect($author->can('update', $message))->toBeTrue();
});

test('a non-author cannot edit a message even as an admin', function () {
    $author = User::factory()->create();
    [$owner, $team, , $message] = teamWithMessage($author);

    $admin = User::factory()->create();
    $team->memberships()->create(['user_id' => $admin->id, 'role' => TeamRole::Admin]);

    expect($admin->can('update', $message))->toBeFalse()
        ->and($owner->can('update', $message))->toBeFalse();
});

test('the author can delete their own message', function () {
    $author = User::factory()->create();
    [, , , $message] = teamWithMessage($author);

    expect($author->can('delete', $message))->toBeTrue();
});

test('a team admin can delete another members message', function () {
    $author = User::factory()->create();
    [$owner, $team, , $message] = teamWithMessage($author);

    $admin = User::factory()->create();
    $team->memberships()->create(['user_id' => $admin->id, 'role' => TeamRole::Admin]);

    expect($admin->can('delete', $message))->toBeTrue()
        ->and($owner->can('delete', $message))->toBeTrue();
});

test('a plain member cannot delete another members message', function () {
    $author = User::factory()->create();
    [, $team, , $message] = teamWithMessage($author);

    $member = User::factory()->create();
    $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);

    expect($member->can('delete', $message))->toBeFalse();
});
