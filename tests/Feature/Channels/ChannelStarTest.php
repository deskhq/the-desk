<?php

use App\Actions\Teams\CreateTeam;
use App\Enums\ChannelVisibility;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\Team;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * Create a team with its owner already a member of #general.
 *
 * @return array{0: User, 1: Team, 2: Channel}
 */
function starTeamWithGeneral(): array
{
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();

    return [$owner, $team, $general];
}

/**
 * Add a user to the team and the given channel, returning them.
 */
function starMember(Team $team, Channel $channel): User
{
    $user = User::factory()->create();
    $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Member]);
    $channel->channelMembers()->firstOrCreate(['user_id' => $user->id]);

    return $user;
}

/**
 * Resolve the sidebar `channels` prop entry for the channel as the acting user.
 *
 * @return array<string, mixed>
 */
function starSidebarEntry(User $user, Team $team, Channel $channel): array
{
    $response = test()->actingAs($user)->get(route('channels.show', [
        'team' => $team->slug,
        'channel' => $channel->slug,
    ]))->assertOk();

    $channels = $response->viewData('page')['props']['channels'];

    return collect($channels)->firstWhere('slug', $channel->slug);
}

/**
 * Hit the star endpoint as the given user.
 */
function setStar(User $user, Team $team, Channel $channel, bool $starred): TestResponse
{
    return test()->actingAs($user)->patch(route('channels.star.update', [
        'team' => $team->slug,
        'channel' => $channel->slug,
    ]), ['starred' => $starred]);
}

test('a member can star a channel', function (): void {
    [, $team, $general] = starTeamWithGeneral();
    $member = starMember($team, $general);

    setStar($member, $team, $general, true)->assertRedirect();

    $this->assertDatabaseHas('channel_members', [
        'channel_id' => $general->id,
        'user_id' => $member->id,
        'starred' => true,
    ]);
});

test('a member can unstar a channel', function (): void {
    [, $team, $general] = starTeamWithGeneral();
    $member = starMember($team, $general);
    $member->channels()->updateExistingPivot($general->id, ['starred' => true]);

    setStar($member, $team, $general, false)->assertRedirect();

    $this->assertDatabaseHas('channel_members', [
        'channel_id' => $general->id,
        'user_id' => $member->id,
        'starred' => false,
    ]);
});

test('the sidebar reflects a channel star flag', function (): void {
    [, $team, $general] = starTeamWithGeneral();
    $member = starMember($team, $general);

    expect(starSidebarEntry($member, $team, $general))
        ->toMatchArray(['starred' => false]);

    $member->channels()->updateExistingPivot($general->id, ['starred' => true]);

    expect(starSidebarEntry($member, $team, $general))
        ->toMatchArray(['starred' => true]);
});

test('one member starring a channel does not star it for another', function (): void {
    [$owner, $team, $general] = starTeamWithGeneral();
    $member = starMember($team, $general);

    setStar($member, $team, $general, true)->assertRedirect();

    expect(starSidebarEntry($owner, $team, $general))->toMatchArray(['starred' => false]);
    expect(starSidebarEntry($member, $team, $general))->toMatchArray(['starred' => true]);
});

test('a non-member cannot star a channel', function (): void {
    [$owner, $team] = starTeamWithGeneral();
    $private = Channel::factory()->for($team)->create([
        'visibility' => ChannelVisibility::Private,
        'created_by' => $owner->id,
    ]);
    $stranger = User::factory()->create();
    $team->memberships()->create(['user_id' => $stranger->id, 'role' => TeamRole::Member]);

    setStar($stranger, $team, $private, true)->assertForbidden();

    $this->assertDatabaseMissing('channel_members', [
        'channel_id' => $private->id,
        'user_id' => $stranger->id,
    ]);
});

test('starring requires a boolean flag', function (): void {
    [, $team, $general] = starTeamWithGeneral();
    $member = starMember($team, $general);

    test()->actingAs($member)->patch(route('channels.star.update', [
        'team' => $team->slug,
        'channel' => $general->slug,
    ]), ['starred' => 'nope'])->assertSessionHasErrors('starred');
});
