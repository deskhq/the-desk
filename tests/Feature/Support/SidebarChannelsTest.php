<?php

use App\Actions\Channels\CreateChannel;
use App\Actions\Channels\OpenDirectMessage;
use App\Actions\Teams\CreateTeam;
use App\Data\ChannelData;
use App\Enums\ChannelVisibility;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use App\Support\SidebarChannels;

/**
 * A workspace owner, their team, and its auto-created #general channel. The
 * read-model is driven straight against these — no HTTP round-trip.
 *
 * @return array{0: User, 1: Team, 2: Channel}
 */
function sidebarWorkspace(): array
{
    $user = User::factory()->create();
    $team = app(CreateTeam::class)->handle($user, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', Channel::GENERAL_SLUG)->firstOrFail();

    return [$user, $team, $general];
}

/**
 * The slugs the read-model lists, in the order it lists them.
 *
 * @param  array<int, ChannelData>  $channels
 * @return array<int, string>
 */
function sidebarSlugs(array $channels): array
{
    return array_map(fn (ChannelData $channel): string => $channel->slug, $channels);
}

test('the list holds the viewer\'s channels in the team, ordered by position then name', function (): void {
    [$user, $team, $general] = sidebarWorkspace();

    $zulu = app(CreateChannel::class)->handle($team, 'zulu', ChannelVisibility::Public, $user);
    $alpha = app(CreateChannel::class)->handle($team, 'alpha', ChannelVisibility::Public, $user);

    // Channels the viewer has never reordered share a position and fall back to
    // the alphabetical tiebreak.
    $user->channels()->sync([$general->id => ['position' => 0], $zulu->id => ['position' => 0], $alpha->id => ['position' => 0]], detaching: false);

    expect(sidebarSlugs(new SidebarChannels($user, $team)->forSidebar()))
        ->toBe([$alpha->slug, $general->slug, $zulu->slug]);

    // A manual placement wins over that tiebreak.
    $user->channels()->updateExistingPivot($zulu->id, ['position' => -1]);

    expect(sidebarSlugs(new SidebarChannels($user, $team)->forSidebar()))
        ->toBe([$zulu->slug, $alpha->slug, $general->slug]);
});

test('the list is scoped to the team and excludes archived channels and non-memberships', function (): void {
    [$user, $team, $general] = sidebarWorkspace();

    $archived = app(CreateChannel::class)->handle($team, 'Archived', ChannelVisibility::Public, $user);
    $archived->update(['archived_at' => now()]);

    // A public channel in the same team the viewer has not joined.
    $stranger = User::factory()->create();
    $team->memberships()->create(['user_id' => $stranger->id, 'role' => TeamRole::Member]);
    app(CreateChannel::class)->handle($team, 'Theirs', ChannelVisibility::Public, $stranger);

    // The viewer's channel in another workspace of their own.
    $other = app(CreateTeam::class)->handle($user, 'Other');
    app(CreateChannel::class)->handle($other, 'Elsewhere', ChannelVisibility::Public, $user);

    expect(sidebarSlugs(new SidebarChannels($user, $team)->forSidebar()))->toBe([$general->slug]);
});

test('unread and mention counts ride along, ignoring the viewer\'s own messages', function (): void {
    [$user, $team, $general] = sidebarWorkspace();

    $mate = User::factory()->create();
    $team->memberships()->create(['user_id' => $mate->id, 'role' => TeamRole::Member]);

    Message::factory()->for($general)->for($user)->create();
    $theirs = Message::factory()->for($general)->for($mate)->count(3)->create();
    $theirs->last()->mentionedUsers()->attach($user);

    $channels = new SidebarChannels($user, $team)->forSidebar();

    expect($channels[0]->unreadCount)->toBe(3)
        ->and($channels[0]->mentionCount)->toBe(1);
});

test('a draft is reported as a flag without shipping its text', function (): void {
    [$user, $team, $general] = sidebarWorkspace();

    $user->channels()->updateExistingPivot($general->id, ['draft' => 'half a thought']);

    $channels = new SidebarChannels($user, $team)->forSidebar();

    expect($channels[0]->hasDraft)->toBeTrue()
        ->and($channels[0]->draft)->toBeNull();
});

test('an empty direct message is listed for its initiator but hidden from the recipient', function (): void {
    [$user, $team] = sidebarWorkspace();

    $mate = User::factory()->create();
    $team->memberships()->create(['user_id' => $mate->id, 'role' => TeamRole::Member]);

    $dm = app(OpenDirectMessage::class)->handle($team, $user, $mate);

    $this->actingAs($user);
    expect(sidebarSlugs(new SidebarChannels($user, $team)->forSidebar()))->toContain($dm->slug);

    $this->actingAs($mate);
    expect(sidebarSlugs(new SidebarChannels($mate, $team)->forSidebar()))->not->toContain($dm->slug);

    // The recipient's own view of it opens once there is something to read.
    Message::factory()->for($dm)->for($user)->create();

    expect(sidebarSlugs(new SidebarChannels($mate, $team)->forSidebar()))->toContain($dm->slug);
});

test('the channel being viewed is listed even while it is still empty', function (): void {
    [$user, $team] = sidebarWorkspace();

    $mate = User::factory()->create();
    $team->memberships()->create(['user_id' => $mate->id, 'role' => TeamRole::Member]);
    $dm = app(OpenDirectMessage::class)->handle($team, $user, $mate);

    $this->actingAs($mate);

    expect(sidebarSlugs(new SidebarChannels($mate, $team)->forSidebar($dm)))->toContain($dm->slug);
});

test('a closed direct message stays out until a message arrives after the close', function (): void {
    [$user, $team] = sidebarWorkspace();

    $mate = User::factory()->create();
    $team->memberships()->create(['user_id' => $mate->id, 'role' => TeamRole::Member]);
    $dm = app(OpenDirectMessage::class)->handle($team, $user, $mate);
    Message::factory()->for($dm)->for($mate)->create(['created_at' => now()->subHour()]);

    $this->actingAs($user);
    $user->channels()->updateExistingPivot($dm->id, ['hidden_at' => now()]);

    expect(sidebarSlugs(new SidebarChannels($user, $team)->forSidebar($dm)))->not->toContain($dm->slug);

    Message::factory()->for($dm)->for($mate)->create(['created_at' => now()->addMinute()]);

    expect(sidebarSlugs(new SidebarChannels($user, $team)->forSidebar($dm)))->toContain($dm->slug);
});
