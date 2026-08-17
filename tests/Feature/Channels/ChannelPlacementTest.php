<?php

declare(strict_types=1);

use App\Enums\ChannelVisibility;
use App\Models\Channel;
use App\Models\ChannelSection;
use App\Models\Team;
use App\Models\User;
use App\Support\SidebarChannels;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
|--------------------------------------------------------------------------
| Channel placement (#1117)
|--------------------------------------------------------------------------
|
| The endpoint that files a channel under a section and reorders the sidebar,
| and who may reach it. What the sidebar then renders off those two columns is
| stated against `SidebarChannels` in
| `tests/Integration/Support/SidebarChannelsTest.php`, so the one claim left
| here that reaches past the pivot reads the read-model rather than a page.
|
*/

/**
 * Create a public channel in the team the user is a member of.
 */
function placementChannel(User $user, Team $team, string $name): Channel
{
    $channel = Channel::factory()->for($team)->create([
        'name' => $name,
        'slug' => Str::slug($name),
        'visibility' => ChannelVisibility::Public,
        'created_by' => $user->id,
    ]);
    channelMembership($channel, $user);

    return $channel;
}

/**
 * Hit the placement endpoint for the channel.
 *
 * @param  array<string, mixed>  $payload
 */
function placeChannel(User $user, Team $team, Channel $channel, array $payload): TestResponse
{
    return test()->actingAs($user)->patch(route('channels.placement.update', [
        'team' => $team->slug,
        'channel' => $channel->slug,
    ]), $payload);
}

test('a member can move a channel into a custom section', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $section = ChannelSection::factory()->for($owner)->for($team)->create();

    placeChannel($owner, $team, $general, [
        'section_id' => $section->id,
        'ordered_ids' => [$general->id],
    ])->assertRedirect();

    $this->assertDatabaseHas('channel_members', [
        'channel_id' => $general->id,
        'user_id' => $owner->id,
        'section_id' => $section->id,
        'position' => 0,
    ]);

});

test('a member can move a channel back to the default group with a null section', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $section = ChannelSection::factory()->for($owner)->for($team)->create();
    $owner->channels()->updateExistingPivot($general->id, ['section_id' => $section->id]);

    placeChannel($owner, $team, $general, [
        'section_id' => null,
        'ordered_ids' => [$general->id],
    ])->assertRedirect();

    $this->assertDatabaseHas('channel_members', [
        'channel_id' => $general->id,
        'user_id' => $owner->id,
        'section_id' => null,
    ]);
});

test('a pure reorder leaves the section assignment untouched', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $section = ChannelSection::factory()->for($owner)->for($team)->create();
    $owner->channels()->updateExistingPivot($general->id, ['section_id' => $section->id]);

    // No section_id key -> reorder only.
    placeChannel($owner, $team, $general, [
        'ordered_ids' => [$general->id],
    ])->assertRedirect();

    $this->assertDatabaseHas('channel_members', [
        'channel_id' => $general->id,
        'user_id' => $owner->id,
        'section_id' => $section->id,
    ]);
});

test('reordering persists channel positions and drives the sidebar order', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $alpha = placementChannel($owner, $team, 'Alpha');
    $beta = placementChannel($owner, $team, 'Beta');

    // Default alphabetical order is general, alpha, beta (all position 0). Flip it.
    placeChannel($owner, $team, $general, [
        'ordered_ids' => [$beta->id, $alpha->id, $general->id],
    ])->assertRedirect();

    expect($beta->fresh()->channelMembers()->where('user_id', $owner->id)->value('position'))->toBe(0)
        ->and($alpha->fresh()->channelMembers()->where('user_id', $owner->id)->value('position'))->toBe(1)
        ->and($general->fresh()->channelMembers()->where('user_id', $owner->id)->value('position'))->toBe(2);

    expect(array_column(new SidebarChannels($owner, $team)->forSidebar(), 'slug'))
        ->toBe(['beta', 'alpha', 'general']);
});

test('placement only touches the acting user own rows', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $member = teamMemberInChannel($general);

    placeChannel($owner, $team, $general, [
        'ordered_ids' => [$general->id],
        'section_id' => null,
    ])->assertRedirect();

    // The other member's position stays at its default.
    expect($general->channelMembers()->where('user_id', $member->id)->value('position'))->toBe(0);
});

test('a member cannot place a channel into another user section', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $other = User::factory()->create();
    $theirs = ChannelSection::factory()->for($other)->for($team)->create();

    placeChannel($owner, $team, $general, [
        'section_id' => $theirs->id,
        'ordered_ids' => [$general->id],
    ])->assertSessionHasErrors('section_id');
});

test('a non-member cannot place a channel', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $private = Channel::factory()->for($team)->create([
        'visibility' => ChannelVisibility::Private,
        'created_by' => $owner->id,
    ]);
    $stranger = teamMemberInChannel($general);

    placeChannel($stranger, $team, $private, [
        'ordered_ids' => [$private->id],
    ])->assertForbidden();
});

test('placement requires the ordered ids payload', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();

    placeChannel($owner, $team, $general, [])->assertSessionHasErrors('ordered_ids');
});

test('placement rejects a channel id the user does not belong to', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $foreign = Channel::factory()->for($team)->create();

    placeChannel($owner, $team, $general, [
        'ordered_ids' => [$foreign->id],
    ])->assertSessionHasErrors('ordered_ids.0');
});

test('a guest cannot place a channel', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();

    $this->patch(route('channels.placement.update', ['team' => $team->slug, 'channel' => $general->slug]), [
        'ordered_ids' => [$general->id],
    ])->assertRedirect(route('login'));
});
