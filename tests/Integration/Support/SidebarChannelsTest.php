<?php

use App\Actions\Channels\CreateChannel;
use App\Actions\Channels\DeleteChannel;
use App\Actions\Channels\OpenDirectMessage;
use App\Actions\Teams\CreateTeam;
use App\Data\ChannelData;
use App\Enums\ChannelVisibility;
use App\Models\Channel;
use App\Models\Message;
use App\Support\ChannelMembership;
use App\Support\SidebarChannels;
use Database\Factories\ChannelMemberFactory;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| The sidebar channel list, driven directly (#1117)
|--------------------------------------------------------------------------
|
| Every claim about *which* channels the sidebar lists, in what order, carrying
| what state, belongs here: `SidebarChannels` is constructible, so there is no
| reason to render `channels/Show` and pluck one prop out of 44 to read it.
| `tests/Feature/Channels/*` keeps the HTTP half — that the page ships the prop
| at all, and who is allowed to reach it.
|
| Every test here constructs and nothing signs in: the read-model's viewer is
| the only viewer there is, all the way down to the viewer-relative half of a
| direct message row (`name`, `dmUserId`), which #1113 made an explicit
| parameter of `ChannelData::fromChannel()`. What that row *says* is proven
| against the DTO in `tests/Integration/Data/ChannelDataTest.php`, and what a
| render of it costs in `SidebarChannelsQueryCountTest`.
|
*/

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
    ['owner' => $user, 'team' => $team, 'channel' => $general] = teamWithChannel();

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
    ['owner' => $user, 'team' => $team, 'channel' => $general] = teamWithChannel();

    $archived = app(CreateChannel::class)->handle($team, 'Archived', ChannelVisibility::Public, $user);
    $archived->update(['archived_at' => now()]);

    // A public channel in the same team the viewer has not joined.
    $stranger = teamMemberInChannel($general);
    app(CreateChannel::class)->handle($team, 'Theirs', ChannelVisibility::Public, $stranger);

    // The viewer's channel in another workspace of their own.
    $other = app(CreateTeam::class)->handle($user, 'Other');
    app(CreateChannel::class)->handle($other, 'Elsewhere', ChannelVisibility::Public, $user);

    expect(sidebarSlugs(new SidebarChannels($user, $team)->forSidebar()))->toBe([$general->slug]);
});

test('an archived channel drops out while its live siblings stay in order', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();

    $active = Channel::factory()->for($team)->create(['name' => 'Announcements', 'slug' => 'announcements', 'created_by' => $owner->id]);
    $active->members()->attach($owner->id);
    $archived = Channel::factory()->for($team)->archived()->create(['created_by' => $owner->id]);
    $archived->members()->attach($owner->id);

    expect(sidebarSlugs(new SidebarChannels($owner, $team)->forSidebar()))
        ->toBe([$active->slug, $general->slug]);
});

test('a deleted channel leaves the list at once', function (): void {
    ['owner' => $owner, 'team' => $team] = teamWithChannel();

    $channel = Channel::factory()->for($team)->create(['name' => 'Roadmap', 'slug' => 'roadmap']);
    $channel->channelMembers()->create(['user_id' => $owner->id]);

    expect(sidebarSlugs(new SidebarChannels($owner, $team)->forSidebar()))->toContain($channel->slug);

    app(DeleteChannel::class)->handle($channel, null);

    expect(sidebarSlugs(new SidebarChannels($owner, $team)->forSidebar()))->not->toContain($channel->slug);
});

test('unread and mention counts ride along, ignoring the viewer\'s own messages', function (): void {
    ['owner' => $user, 'team' => $team, 'channel' => $general] = teamWithChannel();

    $mate = teamMemberInChannel($general);

    Message::factory()->for($general)->for($user)->create();
    $theirs = Message::factory()->for($general)->for($mate)->count(3)->create();
    $theirs->last()->mentionedUsers()->attach($user);

    $channels = new SidebarChannels($user, $team)->forSidebar();

    expect($channels[0]->unreadCount)->toBe(3)
        ->and($channels[0]->mentionCount)->toBe(1);
});

test('a draft is reported as a flag without shipping its text', function (): void {
    ['owner' => $user, 'team' => $team, 'channel' => $general] = teamWithChannel();

    channelMembership($general, $user, fn (ChannelMemberFactory $membership): ChannelMemberFactory => $membership->draft('half a thought'));

    $channels = new SidebarChannels($user, $team)->forSidebar();

    expect($channels[0]->hasDraft)->toBeTrue()
        ->and($channels[0]->draft)->toBeNull();
});

test('an empty direct message is listed for its initiator but hidden from the recipient', function (): void {
    ['owner' => $user, 'team' => $team, 'channel' => $general] = teamWithChannel();

    $mate = teamMemberInChannel($general);

    $dm = app(OpenDirectMessage::class)->handle($team, $user, $mate);

    expect(sidebarSlugs(new SidebarChannels($user, $team)->forSidebar()))->toContain($dm->slug);

    expect(sidebarSlugs(new SidebarChannels($mate, $team)->forSidebar()))->not->toContain($dm->slug);

    // The recipient's own view of it opens once there is something to read.
    Message::factory()->for($dm)->for($user)->create();

    expect(sidebarSlugs(new SidebarChannels($mate, $team)->forSidebar()))->toContain($dm->slug);
});

test('the channel being viewed is listed even while it is still empty', function (): void {
    ['owner' => $user, 'team' => $team, 'channel' => $general] = teamWithChannel();

    $mate = teamMemberInChannel($general);
    $dm = app(OpenDirectMessage::class)->handle($team, $user, $mate);

    expect(sidebarSlugs(new SidebarChannels($mate, $team)->forSidebar($dm)))->toContain($dm->slug);
});

test('a direct message row carries the other participant\'s identity', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();

    $other = teamMemberInChannel($general);
    $dm = app(OpenDirectMessage::class)->handle($team, $owner, $other);

    $row = collect(new SidebarChannels($owner, $team)->forSidebar())->firstWhere('slug', $dm->slug);

    expect($row)->not->toBeNull()
        ->and($row->isDirect)->toBeTrue()
        ->and($row->name)->toBe($other->name)
        ->and($row->dmUserId)->toBe($other->id);
});

// Only the timestamp is asserted, not an order: `SidebarChannels` sorts on
// position then name, and the "Direct messages" group is re-ranked on this value
// client-side (`resources/js/composables/quickSwitcher.ts`). Asserting a DM order
// here would be asserting something the read-model does not do.
test('a direct message carries the latest message activity the client ranks it on', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();

    $other = teamMemberInChannel($general);
    $dm = app(OpenDirectMessage::class)->handle($team, $owner, $other);
    $message = Message::factory()->for($dm)->for($owner, 'user')->create();

    $row = collect(new SidebarChannels($owner, $team)->forSidebar())->firstWhere('slug', $dm->slug);

    expect($row->lastActivityAt)->not->toBeNull()
        ->and(Carbon::parse($row->lastActivityAt)->timestamp)->toBe($message->created_at->timestamp);
});

test('closing a direct message drops it, even the one being viewed', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();

    $other = teamMemberInChannel($general);
    $dm = app(OpenDirectMessage::class)->handle($team, $owner, $other);
    Message::factory()->for($dm)->for($other, 'user')->create(['created_at' => now()->subHour()]);

    expect(sidebarSlugs(new SidebarChannels($owner, $team)->forSidebar($dm)))->toContain($dm->slug);

    new ChannelMembership($dm, $owner)->hide();

    // The close wins even over the active-channel override.
    expect(sidebarSlugs(new SidebarChannels($owner, $team)->forSidebar($dm)))->not->toContain($dm->slug);
});

test('closing an empty direct message the viewer opened drops it too', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();

    $other = teamMemberInChannel($general);

    // An empty DM the owner opened lists via the creator override, with no
    // messages, so closing it exercises the "no message since" predicate branch.
    $dm = app(OpenDirectMessage::class)->handle($team, $owner, $other);

    expect(sidebarSlugs(new SidebarChannels($owner, $team)->forSidebar()))->toContain($dm->slug);

    new ChannelMembership($dm, $owner)->hide();

    expect(sidebarSlugs(new SidebarChannels($owner, $team)->forSidebar()))->not->toContain($dm->slug);
});

test('a message after closing re-surfaces the direct message with an unread badge', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();

    $other = teamMemberInChannel($general);
    $dm = app(OpenDirectMessage::class)->handle($team, $owner, $other);
    Message::factory()->for($dm)->for($owner, 'user')->create();

    new ChannelMembership($dm, $owner)->hide();

    expect(sidebarSlugs(new SidebarChannels($owner, $team)->forSidebar()))->not->toContain($dm->slug);

    // A later reply from the other participant arrives after the close instant.
    Message::factory()->for($dm)->for($other, 'user')->create(['created_at' => now()->addMinutes(5)]);

    $row = collect(new SidebarChannels($owner, $team)->forSidebar())->firstWhere('slug', $dm->slug);

    expect($row)->not->toBeNull()
        ->and($row->unreadCount)->toBe(1);
});

test('reopening a closed direct message un-hides it', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();

    $other = teamMemberInChannel($general);
    $dm = app(OpenDirectMessage::class)->handle($team, $owner, $other);
    Message::factory()->for($dm)->for($owner, 'user')->create();

    new ChannelMembership($dm, $owner)->hide();

    expect(sidebarSlugs(new SidebarChannels($owner, $team)->forSidebar()))->not->toContain($dm->slug);

    // Opening the same DM again (people picker / quick switcher) resurfaces it.
    app(OpenDirectMessage::class)->handle($team, $owner, $other);

    expect(sidebarSlugs(new SidebarChannels($owner, $team)->forSidebar()))->toContain($dm->slug);
});

test('closing is per member and leaves the other participant\'s list alone', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();

    $other = teamMemberInChannel($general);
    $dm = app(OpenDirectMessage::class)->handle($team, $owner, $other);
    Message::factory()->for($dm)->for($owner, 'user')->create();

    new ChannelMembership($dm, $owner)->hide();

    expect(sidebarSlugs(new SidebarChannels($owner, $team)->forSidebar()))->not->toContain($dm->slug);

    expect(sidebarSlugs(new SidebarChannels($other, $team)->forSidebar()))->toContain($dm->slug);
});
