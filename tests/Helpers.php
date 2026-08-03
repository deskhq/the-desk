<?php

declare(strict_types=1);

use App\Actions\Teams\CreateTeam;
use App\Data\ChannelData;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\ChannelMember;
use App\Models\Team;
use App\Models\User;
use App\Support\SidebarChannels;
use Database\Factories\ChannelMemberFactory;

/*
|--------------------------------------------------------------------------
| The shared arrange (#1111)
|--------------------------------------------------------------------------
|
| A team, a channel, and someone in both — what nearly every test in this
| repository opens with, and what fifty-odd test files each re-declared for
| themselves under a private prefix (`threadTeamWithGeneral`,
| `pinTeamWithGeneral`, `draftMember`, ...). `tests/Browser/Helpers.php` has
| carried the same arrange for the browser suite since #53 and is used by 68
| of its 74 files with no local clones at all, so the mechanism is proven;
| these are its headless counterparts, required from `tests/Pest.php`.
|
| Membership state goes through `ChannelMemberFactory` rather than an
| attribute array, so `muted`, `starred`, `draft` and `notification_level`
| have exactly one spelling in the test suite too, and a fifth state is
| reachable from here the day the factory declares it.
|
*/

/**
 * A team and its auto-created #general channel, with the owner in both.
 *
 * Built through {@see CreateTeam} rather than the factories directly, because
 * the arrange every caller actually wants includes what creating a team *does*
 * — the owner's membership, their current team, and #general itself.
 *
 * @return array{owner: User, team: Team, channel: Channel}
 */
function teamWithChannel(string $teamName = 'Acme'): array
{
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, $teamName);

    $channel = Channel::query()
        ->where('team_id', $team->id)
        ->where('slug', Channel::GENERAL_SLUG)
        ->firstOrFail();

    return ['owner' => $owner, 'team' => $team, 'channel' => $channel];
}

/**
 * Another user, in the channel's team and in the channel itself.
 *
 * The team is taken from the channel rather than passed alongside it: the two
 * can only ever be the one pair, and a signature that accepts both is a
 * signature that can be handed a mismatched one.
 *
 * @param  array<string, mixed>  $attributes  overrides for the user, e.g. a name a mention has to match
 * @param  (Closure(ChannelMemberFactory): ChannelMemberFactory)|null  $state  the membership state, see {@see channelMembership()}
 */
function teamMemberInChannel(
    Channel $channel,
    array $attributes = [],
    TeamRole $role = TeamRole::Member,
    ?Closure $state = null,
): User {
    return joinTeamAndChannel($channel, User::factory()->create($attributes), $role, $state);
}

/**
 * The same arrange for a user who already exists.
 *
 * {@see teamMemberInChannel()} covers the common case, where the test cares
 * about the membership and not about who is holding it. This one is for when
 * the user has to be built first — because a *factory state* shapes them
 * (`withoutReadReceipts()`) or because they are already someone in the story,
 * joining a second team.
 *
 * @param  (Closure(ChannelMemberFactory): ChannelMemberFactory)|null  $state  the membership state, see {@see channelMembership()}
 */
function joinTeamAndChannel(
    Channel $channel,
    User $user,
    TeamRole $role = TeamRole::Member,
    ?Closure $state = null,
): User {
    $channel->team->memberships()->create(['user_id' => $user->id, 'role' => $role]);

    channelMembership($channel, $user, $state);

    return $user;
}

/**
 * The row the sidebar lists for the channel, as the given viewer sees it.
 *
 * Taken off {@see SidebarChannels}, which is what builds the `channels` prop, so
 * a test that wants one row's state no longer renders `channels/Show` and plucks
 * it out of the 44 props `share()` ships (#1117). What the list *holds* — which
 * channels, in what order, carrying what state — is stated in
 * `tests/Integration/Support/SidebarChannelsTest.php`; this is for the tests
 * whose subject is an endpoint that writes one of those columns.
 */
function sidebarRow(User $viewer, Team $team, Channel $channel): ChannelData
{
    $row = collect(new SidebarChannels($viewer, $team)->forSidebar())
        ->firstWhere('slug', $channel->slug);

    expect($row)->toBeInstanceOf(ChannelData::class);

    /** @var ChannelData $row */
    return $row;
}

/**
 * The membership joining a user to a channel, in whatever state the test needs:
 *
 *     channelMembership($general, $viewer, fn ($membership) => $membership->muted());
 *
 * The state arrives as a closure over {@see ChannelMemberFactory} so that this
 * helper names none of the columns the states write — which is the point, given
 * the pivot's column set is already declared in more places than it should be.
 *
 * A membership that already exists is *restated* rather than replaced: the team
 * owner is auto-joined to #general, so a good half of the arranges land on a
 * row that is already there, and the rest of it — a read cursor, a section, a
 * position — belongs to whatever put it there and not to this helper.
 *
 * @param  (Closure(ChannelMemberFactory): ChannelMemberFactory)|null  $state
 */
function channelMembership(Channel $channel, User $user, ?Closure $state = null): ChannelMember
{
    $membership = $channel->channelMembers()->firstWhere('user_id', $user->id);

    // Seeded from the row when there is one, so the states overwrite only what
    // the test asked for and the rest of it is left standing.
    $seed = $membership instanceof ChannelMember
        ? $membership->getAttributes()
        : ['channel_id' => $channel->id, 'user_id' => $user->id];

    $factory = ChannelMember::factory()->state($seed);
    $stated = $state instanceof Closure ? $state($factory) : $factory;

    if (! $membership instanceof ChannelMember) {
        return $stated->create();
    }

    $membership->forceFill($stated->make()->getAttributes())->save();

    return $membership;
}
