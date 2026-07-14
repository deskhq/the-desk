<?php

use App\Actions\Channels\OpenDirectMessage;
use App\Actions\Teams\CreateTeam;
use App\Enums\MessageType;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\Team;
use App\Models\User;

/**
 * Add a plain member to the team and return them.
 */
function leaveGroupTeamMember(Team $team): User
{
    $user = User::factory()->create();
    $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Member]);

    return $user;
}

/**
 * Open a group DM spanning the owner and two other members.
 *
 * @return array{0: User, 1: Team, 2: User, 3: Channel}
 */
function teamWithGroupDm(): array
{
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $ana = leaveGroupTeamMember($team);
    $tomas = leaveGroupTeamMember($team);

    $group = app(OpenDirectMessage::class)->openForUsers($team, $owner, collect([$ana, $tomas]));

    return [$owner, $team, $ana, $group];
}

test('a member can leave a group direct message and is redirected to #general', function (): void {
    [$owner, $team, , $group] = teamWithGroupDm();

    $this->actingAs($owner)
        ->post(route('channels.leave', ['team' => $team->slug, 'channel' => $group->slug]))
        ->assertRedirect(route('channels.show', ['team' => $team->slug, 'channel' => Channel::GENERAL_SLUG]));

    expect($group->members()->whereKey($owner->id)->exists())->toBeFalse()
        ->and($group->members()->count())->toBe(2);
});

test('leaving a group direct message records a member-left notice for the others', function (): void {
    [$owner, $team, , $group] = teamWithGroupDm();

    $this->actingAs($owner)->post(route('channels.leave', ['team' => $team->slug, 'channel' => $group->slug]));

    expect($group->messages()->where('type', MessageType::MemberLeft)->where('user_id', $owner->id)->exists())->toBeTrue();
});

test('a 1:1 direct message cannot be left', function (): void {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $other = leaveGroupTeamMember($team);
    $dm = app(OpenDirectMessage::class)->handle($team, $owner, $other);

    $this->actingAs($owner)
        ->post(route('channels.leave', ['team' => $team->slug, 'channel' => $dm->slug]))
        ->assertForbidden();

    expect($dm->members()->whereKey($owner->id)->exists())->toBeTrue();
});
