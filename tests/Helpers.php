<?php

declare(strict_types=1);

use App\Actions\Teams\CreateTeam;
use App\Data\ChannelData;
use App\Data\UnreadCountsData;
use App\Enums\TeamRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Channel;
use App\Models\ChannelMember;
use App\Models\Team;
use App\Models\User;
use App\Support\SidebarChannels;
use App\Support\WorkspaceUnread;
use Database\Factories\ChannelMemberFactory;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

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
 * What the shell's unread digest says is waiting in the channel, as the given
 * viewer sees it — the badge the sidebar row draws.
 *
 * The counts left {@see sidebarRow()} when the roster and the unread state were
 * split (#1249), so a test about a badge asks here and a test about a row's
 * placement, mute or draft asks there. Both readings of the digest are sparse,
 * so "nothing waiting" arrives as two zeroes rather than as a missing key.
 *
 * @return array{unread: int, mention: int}
 */
function unreadBadge(User $viewer, Team $team, Channel $channel): array
{
    return unreadCounts(WorkspaceUnread::digest($viewer, $team)->channels[$channel->id] ?? null);
}

/**
 * The same reading for a whole workspace: the rail's dot and the workspace
 * sheet's badge.
 *
 * @return array{unread: int, mention: int}
 */
function workspaceUnreadBadge(User $viewer, Team $team): array
{
    return unreadCounts(WorkspaceUnread::digest($viewer)->teams[$team->id] ?? null);
}

/**
 * One entry of the digest as a plain pair, defaulting an absent one to zero.
 *
 * @return array{unread: int, mention: int}
 */
function unreadCounts(?UnreadCountsData $counts): array
{
    return ['unread' => $counts?->unread ?? 0, 'mention' => $counts?->mention ?? 0];
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

/*
|--------------------------------------------------------------------------
| The second visit (#1251)
|--------------------------------------------------------------------------
|
| Most of the shell contract is a statement about the *second* click, and a
| test can only make it by arriving the way the SPA does: as an Inertia
| request that declares back every once prop the previous response handed
| over. Hence {@see revisit()}, which reads those keys off the first response
| rather than spelling them out — the keys are a private arrangement between
| the middleware and the client, and a test that hardcoded them would be
| asserting the key format instead of the behaviour it carries.
|
*/

/**
 * The URL of a team's #general — the workspace visit the shell tests drive.
 */
function workspaceUrl(Team $team): string
{
    return route('channels.show', ['team' => $team->slug, 'channel' => Channel::GENERAL_SLUG]);
}

/**
 * A workspace visit to the team's #general as the given viewer.
 */
function visitWorkspaceAs(User $viewer, Team $team): TestResponse
{
    return test()->actingAs($viewer)->get(workspaceUrl($team));
}

/**
 * The once-prop keys a response handed the client, whether it rendered a
 * document or answered an Inertia visit.
 *
 * @return array<int, string>
 */
function oncePropKeys(TestResponse $response): array
{
    $page = $response->headers->get('X-Inertia') === 'true'
        ? (array) $response->json()
        : (array) $response->viewData('page');

    /** @var array<string, mixed> $onceProps */
    $onceProps = $page['onceProps'] ?? [];

    return array_keys($onceProps);
}

/**
 * When the client is told a once prop expires, as a millisecond timestamp —
 * null when it is told to hold it indefinitely.
 *
 * Looked up by prop name rather than by key, because the key is the middleware's
 * business and a test that spelled one out would break on every change to how
 * they are composed.
 */
function onceExpiry(TestResponse $response, string $prop): ?int
{
    $page = (array) $response->viewData('page');

    /** @var array<string, array{prop: string, expiresAt: int|null}> $onceProps */
    $onceProps = $page['onceProps'] ?? [];

    $entry = collect($onceProps)->firstWhere('prop', $prop);

    expect($entry)->not->toBeNull("no once metadata for [{$prop}]");

    return $entry['expiresAt'];
}

/**
 * The next visit in the same session, made the way the client makes it: over
 * Inertia, declaring back the once props `$previous` delivered.
 *
 * @param  array<string, string>  $headers  extra headers, e.g. a partial reload's
 */
function revisit(TestResponse $previous, string $url, array $headers = []): TestResponse
{
    return test()->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
        'X-Inertia-Except-Once-Props' => implode(',', oncePropKeys($previous)),
        ...$headers,
    ])->get($url);
}

/*
|--------------------------------------------------------------------------
| Guarded egress (#1196)
|--------------------------------------------------------------------------
|
| Every outbound connection to a member-controlled URL goes through
| `App\Support\Http\GuardedEgress`, which pins each hop to the address the SSRF
| guard vetted. Proving that took a bespoke capture helper in one of the three
| callers' test files and nothing at all in the other two, so the pin was
| asserted at one call site of three. This is that helper, shared.
|
| The DNS half lives in `Tests\Support\StubHostResolver`.
|
*/

/**
 * Fake every outbound request, recording the transport options it went out
 * with, so a test can read back the address curl was pinned to:
 *
 *     captureTransportOptions(['https://example.test' => Http::response()], $captured);
 *     expect($captured[0]['curl'][CURLOPT_RESOLVE])->toBe(['example.test:443:93.184.216.34']);
 *
 * @param  array<string, PromiseInterface>  $responses  keyed by requested URL
 * @param  array<int, array<string, mixed>>|null  $captured
 */
function captureTransportOptions(array $responses, ?array &$captured): void
{
    $captured = [];

    Http::fake(function (Request $request, array $options) use ($responses, &$captured): PromiseInterface {
        $captured[] = $options;

        return $responses[$request->url()];
    });
}
