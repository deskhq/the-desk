<?php

use App\Actions\Channels\OpenDirectMessage;
use App\Actions\Teams\CreateTeam;
use App\Enums\ChannelType;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\Team;
use App\Models\User;

/**
 * Add a plain member to the team and return them.
 */
function peopleTeamMember(Team $team): User
{
    $user = User::factory()->create();
    $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Member]);

    return $user;
}

/**
 * Open a 1:1 DM between the owner and another member.
 *
 * @return array{0: User, 1: Team, 2: User, 3: Channel}
 */
function teamWithOneOnOne(): array
{
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $other = peopleTeamMember($team);

    $dm = app(OpenDirectMessage::class)->handle($team, $owner, $other);

    return [$owner, $team, $other, $dm];
}

test('adding a person to a 1:1 opens a group direct message with all three', function (): void {
    [$owner, $team, $other, $dm] = teamWithOneOnOne();
    $third = peopleTeamMember($team);

    $this->actingAs($owner)
        ->post(route('channels.dm.people.store', ['team' => $team->slug, 'channel' => $dm->slug]), ['user_ids' => [$third->id]])
        ->assertRedirect();

    $group = Channel::where('team_id', $team->id)->where('type', ChannelType::GroupDirect)->firstOrFail();

    expect($group->members()->count())->toBe(3)
        ->and($group->members()->whereKey($owner->id)->exists())->toBeTrue()
        ->and($group->members()->whereKey($other->id)->exists())->toBeTrue()
        ->and($group->members()->whereKey($third->id)->exists())->toBeTrue();
});

test('adding people redirects into the resulting conversation', function (): void {
    [$owner, $team, , $dm] = teamWithOneOnOne();
    $third = peopleTeamMember($team);

    $response = $this->actingAs($owner)
        ->post(route('channels.dm.people.store', ['team' => $team->slug, 'channel' => $dm->slug]), ['user_ids' => [$third->id]]);

    $group = Channel::where('team_id', $team->id)->where('type', ChannelType::GroupDirect)->firstOrFail();

    $response->assertRedirect(route('channels.show', ['team' => $team->slug, 'channel' => $group->slug]));
});

test('adding people reuses an existing conversation with the same member set', function (): void {
    [$owner, $team, , $dm] = teamWithOneOnOne();
    $third = peopleTeamMember($team);

    $this->actingAs($owner)
        ->post(route('channels.dm.people.store', ['team' => $team->slug, 'channel' => $dm->slug]), ['user_ids' => [$third->id]]);
    // A second add of the same third person from the original 1:1 lands in the
    // same group rather than spawning a duplicate.
    $this->actingAs($owner)
        ->post(route('channels.dm.people.store', ['team' => $team->slug, 'channel' => $dm->slug]), ['user_ids' => [$third->id]]);

    expect(Channel::where('team_id', $team->id)->where('type', ChannelType::GroupDirect)->count())->toBe(1);
});

test('a non-member cannot add people to a direct message', function (): void {
    [, $team, , $dm] = teamWithOneOnOne();
    $bystander = peopleTeamMember($team);
    $target = peopleTeamMember($team);

    $this->actingAs($bystander)
        ->post(route('channels.dm.people.store', ['team' => $team->slug, 'channel' => $dm->slug]), ['user_ids' => [$target->id]])
        ->assertForbidden();
});

test('people cannot be added to a standard channel through the DM flow', function (): void {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $channel = $team->channels()->where('slug', 'general')->firstOrFail();
    $target = peopleTeamMember($team);

    $this->actingAs($owner)
        ->post(route('channels.dm.people.store', ['team' => $team->slug, 'channel' => $channel->slug]), ['user_ids' => [$target->id]])
        ->assertForbidden();
});

test('adding people requires at least one valid team member', function (): void {
    [$owner, $team, , $dm] = teamWithOneOnOne();

    $this->actingAs($owner)
        ->post(route('channels.dm.people.store', ['team' => $team->slug, 'channel' => $dm->slug]), ['user_ids' => []])
        ->assertSessionHasErrors('user_ids');

    $outsider = User::factory()->create();
    $this->actingAs($owner)
        ->post(route('channels.dm.people.store', ['team' => $team->slug, 'channel' => $dm->slug]), ['user_ids' => [$outsider->id]])
        ->assertSessionHasErrors('user_ids.0');
});
