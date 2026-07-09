<?php

use App\Actions\Teams\CreateTeam;
use App\Enums\ChannelVisibility;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\ChannelSection;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Create a team with its owner already a member of #general.
 *
 * @return array{0: User, 1: Team, 2: Channel}
 */
function placementTeam(): array
{
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();

    return [$owner, $team, $general];
}

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
    $channel->channelMembers()->create(['user_id' => $user->id]);

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

/**
 * The acting user's sidebar `channels` prop, keyed by slug.
 *
 * @return Collection<string, array<string, mixed>>
 */
function placementSidebar(User $user, Team $team, Channel $channel): Collection
{
    $response = test()->actingAs($user)->get(route('channels.show', [
        'team' => $team->slug,
        'channel' => $channel->slug,
    ]))->assertOk();

    return collect($response->viewData('page')['props']['channels'])->keyBy('slug');
}

test('a member can move a channel into a custom section', function () {
    [$owner, $team, $general] = placementTeam();
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

    expect(placementSidebar($owner, $team, $general)[$general->slug])
        ->toMatchArray(['sectionId' => $section->id, 'position' => 0]);
});

test('a member can move a channel back to the default group with a null section', function () {
    [$owner, $team, $general] = placementTeam();
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

test('a pure reorder leaves the section assignment untouched', function () {
    [$owner, $team, $general] = placementTeam();
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

test('reordering persists channel positions and drives the sidebar order', function () {
    [$owner, $team, $general] = placementTeam();
    $alpha = placementChannel($owner, $team, 'Alpha');
    $beta = placementChannel($owner, $team, 'Beta');

    // Default alphabetical order is general, alpha, beta (all position 0). Flip it.
    placeChannel($owner, $team, $general, [
        'ordered_ids' => [$beta->id, $alpha->id, $general->id],
    ])->assertRedirect();

    expect($beta->fresh()->channelMembers()->where('user_id', $owner->id)->value('position'))->toBe(0)
        ->and($alpha->fresh()->channelMembers()->where('user_id', $owner->id)->value('position'))->toBe(1)
        ->and($general->fresh()->channelMembers()->where('user_id', $owner->id)->value('position'))->toBe(2);

    $order = placementSidebar($owner, $team, $general)->keys()->all();
    expect($order)->toBe(['beta', 'alpha', 'general']);
});

test('placement only touches the acting user own rows', function () {
    [$owner, $team, $general] = placementTeam();
    $member = User::factory()->create();
    $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);
    $general->channelMembers()->firstOrCreate(['user_id' => $member->id]);

    placeChannel($owner, $team, $general, [
        'ordered_ids' => [$general->id],
        'section_id' => null,
    ])->assertRedirect();

    // The other member's position stays at its default.
    expect($general->channelMembers()->where('user_id', $member->id)->value('position'))->toBe(0);
});

test('a member cannot place a channel into another user section', function () {
    [$owner, $team, $general] = placementTeam();
    $other = User::factory()->create();
    $theirs = ChannelSection::factory()->for($other)->for($team)->create();

    placeChannel($owner, $team, $general, [
        'section_id' => $theirs->id,
        'ordered_ids' => [$general->id],
    ])->assertSessionHasErrors('section_id');
});

test('a non-member cannot place a channel', function () {
    [$owner, $team] = placementTeam();
    $private = Channel::factory()->for($team)->create([
        'visibility' => ChannelVisibility::Private,
        'created_by' => $owner->id,
    ]);
    $stranger = User::factory()->create();
    $team->memberships()->create(['user_id' => $stranger->id, 'role' => TeamRole::Member]);

    placeChannel($stranger, $team, $private, [
        'ordered_ids' => [$private->id],
    ])->assertForbidden();
});

test('placement requires the ordered ids payload', function () {
    [$owner, $team, $general] = placementTeam();

    placeChannel($owner, $team, $general, [])->assertSessionHasErrors('ordered_ids');
});

test('placement rejects a channel id the user does not belong to', function () {
    [$owner, $team, $general] = placementTeam();
    $foreign = Channel::factory()->for($team)->create();

    placeChannel($owner, $team, $general, [
        'ordered_ids' => [$foreign->id],
    ])->assertSessionHasErrors('ordered_ids.0');
});

test('a guest cannot place a channel', function () {
    [$owner, $team, $general] = placementTeam();

    $this->patch(route('channels.placement.update', ['team' => $team->slug, 'channel' => $general->slug]), [
        'ordered_ids' => [$general->id],
    ])->assertRedirect(route('login'));
});
