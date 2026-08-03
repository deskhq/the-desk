<?php

use App\Enums\ChannelVisibility;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\Team;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * The sidebar `channels` row for the channel, as the acting user.
 *
 * @return array<string, mixed>
 */
function starSidebarEntry(User $user, Team $team, Channel $channel): array
{
    return sidebarRow($user, $team, $channel)->toArray();
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
    ['team' => $team, 'channel' => $general] = teamWithChannel();
    $member = teamMemberInChannel($general);

    setStar($member, $team, $general, true)->assertRedirect();

    $this->assertDatabaseHas('channel_members', [
        'channel_id' => $general->id,
        'user_id' => $member->id,
        'starred' => true,
    ]);
});

test('a member can unstar a channel', function (): void {
    ['team' => $team, 'channel' => $general] = teamWithChannel();
    $member = teamMemberInChannel($general);
    $member->channels()->updateExistingPivot($general->id, ['starred' => true]);

    setStar($member, $team, $general, false)->assertRedirect();

    $this->assertDatabaseHas('channel_members', [
        'channel_id' => $general->id,
        'user_id' => $member->id,
        'starred' => false,
    ]);
});

test('the sidebar reflects a channel star flag', function (): void {
    ['team' => $team, 'channel' => $general] = teamWithChannel();
    $member = teamMemberInChannel($general);

    expect(starSidebarEntry($member, $team, $general))
        ->toMatchArray(['starred' => false]);

    $member->channels()->updateExistingPivot($general->id, ['starred' => true]);

    expect(starSidebarEntry($member, $team, $general))
        ->toMatchArray(['starred' => true]);
});

test('one member starring a channel does not star it for another', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $member = teamMemberInChannel($general);

    setStar($member, $team, $general, true)->assertRedirect();

    expect(starSidebarEntry($owner, $team, $general))->toMatchArray(['starred' => false]);
    expect(starSidebarEntry($member, $team, $general))->toMatchArray(['starred' => true]);
});

test('a non-member cannot star a channel', function (): void {
    ['owner' => $owner, 'team' => $team] = teamWithChannel();
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
    ['team' => $team, 'channel' => $general] = teamWithChannel();
    $member = teamMemberInChannel($general);

    test()->actingAs($member)->patch(route('channels.star.update', [
        'team' => $team->slug,
        'channel' => $general->slug,
    ]), ['starred' => 'nope'])->assertSessionHasErrors('starred');
});
