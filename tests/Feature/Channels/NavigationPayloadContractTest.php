<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\ChannelMember;
use App\Models\Message;
use App\Models\User;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\ExpectationFailedException;

/*
|--------------------------------------------------------------------------
| The navigation payload contract (#1255)
|--------------------------------------------------------------------------
|
| The standing guard, and the only part of the #1248 budget that outlives the
| epic. Every prop that epic took off the navigation was added by someone
| reasonable solving a real problem; nothing in the codebase told them what it
| cost. This file is that telling.
|
| Two claims, and the second is the one that carries the thesis:
|
| 1. **Set membership.** The shared props of a channel -> channel visit are
|    exactly `{errors, unread, the six affordance flags, sidebarOpen}`. It goes
|    red the moment an unconditional closure lands in `share()`, which is the
|    regression, stated directly.
| 2. **The bytes stop moving.** That residue does not grow with the workspace,
|    asserted at two seeded shapes: the demo's 14 channels / 7 members, and a
|    200 / 300 workspace. A byte *ceiling* would be the wrong instrument here,
|    since a channel visit is dominated by `messages` and the epic never
|    touched it; the claim worth enforcing is not the residue's size but that
|    its size stops moving. A ~1 KB tripwire rides along anyway, for a human
|    reading a diff rather than for the property.
|
| What is deliberately never asserted is **time**. Shared runners are noisy, a
| flaky perf gate is deleted within a month, and the deletion takes the intent
| with it. Scale is proven here; wall-clock is proven on the demo.
|
| The seam is the Inertia HTTP visit, as it has to be: the once-exclusion
| happens in the response pipeline, so a direct call to `share()` would not
| exercise it. `revisit()` in `tests/Helpers.php` makes the second click the
| way the client does, declaring back the once keys the first response handed
| over. Prior art: `WorkspaceShellTest.php` and the three `*OncePropsTest.php`
| files this one closes over.
|
*/

/**
 * What an ordinary workspace navigation is allowed to ship.
 *
 * The errors bag, the one genuinely volatile fact (what the viewer has not
 * read), the six affordance flags that follow the viewer's role rather than
 * anything cacheable, and the cookie-derived sidebar state. Everything else a
 * workspace page needs is declared in `HandleInertiaRequests::shareOnce()`
 * behind a named invalidation trigger, or belongs to the page itself.
 *
 * @var list<string>
 */
const NAVIGATION_PROPS = [
    'errors',
    'unread',
    'canInviteToCurrentTeam',
    'creatableChannelVisibilities',
    'canUpdateCurrentTeam',
    'canViewCurrentTeamAudit',
    'canViewCurrentTeamSecurityLog',
    'canManageCurrentTeamIntegrations',
    'sidebarOpen',
];

/**
 * The tripwire, in bytes of uncompressed JSON.
 *
 * Not the property this file is really about, and not a budget anything was
 * derived from: it is a round number a human can hold, against a residue that
 * measures a few hundred bytes. Crossing it means something has been added to
 * the navigation, and the set-membership assertion above will already have
 * said which.
 */
const SHARED_PROP_CEILING = 1024;

/**
 * How far apart the two shapes' byte counts may land.
 *
 * The residue is the same props holding the same values at both shapes, so in
 * practice the two counts are identical; the allowance is for the incidental
 * width of an id or a count, not for a term that scales. Anything that grows
 * with the workspace blows this by two orders of magnitude, so a loose
 * tolerance costs the assertion nothing.
 */
const SHAPE_TOLERANCE = 64;

/**
 * How many channels hold something unread, at either shape.
 *
 * Held constant on purpose. The digest is sparse and genuinely proportional to
 * what is *waiting*, which is not what this file is about: the claim is that
 * the residue does not grow with the size of the workspace, so the workspace
 * is what varies between the two shapes and the unread standing is not.
 */
const UNREAD_CHANNELS = 3;

dataset('workspace shapes', [
    'the demo' => [14, 7],
    'at scale' => [200, 300],
]);

/**
 * A workspace of a given shape, and the two channels a navigation runs between.
 *
 * Seeded in one statement per table, from one factory-made template row each,
 * which is the one place in this suite that is deliberate rather than lazy.
 * The factories still own which columns exist — every row here is a `make()`
 * of theirs — but they are not `create()`d, for two reasons. A 200 / 300
 * workspace costs four inserts instead of a thousand, and a test slow enough
 * to be annoying is a test someone deletes, which here would delete the
 * contract with it. And `UserFactory::configure()` gives every created user a
 * personal team: three hundred of them would be three hundred workspaces
 * nobody in this story belongs to, seeded to describe a roster.
 *
 * @return array{viewer: User, from: Channel, to: Channel}
 */
function workspaceOfShape(int $channels, int $members): array
{
    // Named for its shape so that a test seeding both of them lands on two
    // workspaces rather than on one slug twice.
    ['owner' => $viewer, 'team' => $team, 'channel' => $general] = teamWithChannel("Shape {$channels}x{$members}");

    $now = now();

    // One template per table, varied by index. The rows are near-identical by
    // design: what the shape has to be honest about is how many of them there
    // are, not how different they are from each other.
    $mate = User::factory()->make()->getAttributes();

    $roster = array_map(fn (int $index): array => [
        ...$mate,
        'id' => (string) Str::uuid(),
        'name' => "Member {$index}",
        'email' => "member-{$index}@{$team->slug}.test",
        'created_at' => $now,
        'updated_at' => $now,
    ], range(1, $members - 1));

    DB::table('users')->insert($roster);
    DB::table('team_members')->insert(array_map(fn (array $row): array => [
        'id' => (string) Str::uuid(),
        'team_id' => $team->id,
        'user_id' => $row['id'],
        'role' => TeamRole::Member->value,
        'created_at' => $now,
        'updated_at' => $now,
    ], $roster));

    $template = Channel::factory()->make([
        'team_id' => $team->id,
        'created_by' => $viewer->id,
    ])->getAttributes();

    $rooms = array_map(fn (int $index): array => [
        ...$template,
        'id' => (string) Str::uuid(),
        'name' => "Room {$index}",
        'slug' => "room-{$index}",
        'created_at' => $now,
        'updated_at' => $now,
    ], range(1, $channels - 1));

    DB::table('channels')->insert($rooms);

    $membership = ChannelMember::factory()->make([
        'channel_id' => $general->id,
        'user_id' => $viewer->id,
    ])->getAttributes();

    DB::table('channel_members')->insert(array_map(fn (array $room): array => [
        ...$membership,
        'id' => (string) Str::uuid(),
        'channel_id' => $room['id'],
        'created_at' => $now,
        'updated_at' => $now,
    ], $rooms));

    // Something actually waiting, in the same few channels at both shapes, so
    // the digest is exercised rather than trivially empty and is still not a
    // term that varies with the shape.
    $author = teamMemberInChannel($general, ['name' => 'Amy Author']);
    $waiting = array_slice([$general->id, ...array_column($rooms, 'id')], 0, UNREAD_CHANNELS);

    foreach ($waiting as $channel) {
        Message::factory()->for($author)->create(['channel_id' => $channel]);
    }

    return [
        'viewer' => $viewer,
        'from' => $general,
        // Somewhere with nothing waiting, so the arrival is an ordinary
        // navigation rather than one that also clears a badge.
        'to' => Channel::query()->findOrFail($rooms[UNREAD_CHANNELS - 1]['id']),
    ];
}

/**
 * The shared props a channel -> channel navigation actually ships, on a
 * workspace of the given shape.
 *
 * Two visits, because the contract is a statement about the *second* click: a
 * document load onto one channel, then an Inertia visit to another, declaring
 * back the once keys the first response handed over. What comes back is the
 * page's own props and the shared residue mixed together, so the residue is
 * picked out by asking Inertia which props were shared at all - the definition
 * of the word, rather than a list this file would have to keep in step.
 *
 * @return array<string, mixed>
 */
function navigationSharedProps(int $channels, int $members): array
{
    ['viewer' => $viewer, 'from' => $from, 'to' => $to] = workspaceOfShape($channels, $members);

    $first = test()->actingAs($viewer)->get(channelUrl($from))->assertOk();

    $props = (array) revisit($first, channelUrl($to))->assertOk()->json('props');

    // Read straight after the response, while the shared set on the Inertia
    // singleton is still the one that request declared.
    return array_intersect_key($props, Inertia::getShared());
}

/**
 * The URL of one channel in its team.
 */
function channelUrl(Channel $channel): string
{
    return route('channels.show', ['team' => $channel->team->slug, 'channel' => $channel->slug]);
}

/**
 * What the shared residue weighs on the wire, uncompressed.
 *
 * @param  array<string, mixed>  $shared
 */
function sharedPropBytes(array $shared): int
{
    return strlen(json_encode($shared, JSON_THROW_ON_ERROR));
}

/**
 * Assert a navigation shipped exactly the props the contract names, and say
 * which one broke it when it did not.
 *
 * A red build here has to teach: the prop is named, the contract is restated,
 * and the two places a new prop can go instead are spelled out. Otherwise the
 * next person to trip it deletes the assertion.
 *
 * @param  array<string, mixed>  $shared
 */
function assertNavigationContract(array $shared): void
{
    $shipped = array_keys($shared);
    $expected = NAVIGATION_PROPS;

    sort($shipped);
    sort($expected);

    expect($shipped)->toBe($expected, contractBreach(
        array_values(array_diff($shipped, $expected)),
        array_values(array_diff($expected, $shipped)),
    ));
}

/**
 * The failure message: what broke, and what to do about it.
 *
 * @param  list<string>  $extra  props riding a navigation that should not be
 * @param  list<string>  $missing  props the contract owes that did not arrive
 */
function contractBreach(array $extra, array $missing): string
{
    $lines = ['The navigation payload contract is broken (#1255).', ''];

    foreach ($extra as $prop) {
        $lines[] = "  + [{$prop}] is shared on every navigation and should not be.";
    }

    foreach ($missing as $prop) {
        $lines[] = "  - [{$prop}] is owed on every navigation and did not arrive.";
    }

    return implode(PHP_EOL, [
        ...$lines,
        '',
        "A workspace navigation ships the page's own props, one unread digest,",
        'the six affordance flags and the sidebar state. Nothing else. A prop',
        'added to HandleInertiaRequests::share() is recomputed and reserialised',
        'on every click, and on a 200-channel workspace that is where roughly',
        '85% of a navigation used to go.',
        '',
        'So: if the prop cannot change between two clicks, declare it in',
        'shareOnce() with an honest invalidation trigger (see the groups already',
        'there). If it belongs to one page, make it a page prop. If it genuinely',
        'moves under the viewer on every navigation, then this contract has to',
        'move with it, and that is a conversation rather than an edit: #1248.',
    ]);
}

test('a navigation ships exactly the props the contract names', function (int $channels, int $members): void {
    assertNavigationContract(navigationSharedProps($channels, $members));
})->with('workspace shapes');

test('a prop shared unconditionally is caught and named', function (): void {
    // The regression itself, staged: a closure that reaches the shared set
    // without a once key, exactly as an added line in share() would. Registered
    // on RouteMatched because it has to run inside the request, after
    // `revisit()` has flushed the singleton the previous one left behind.
    Event::listen(RouteMatched::class, fn (): mixed => Inertia::share('roster', ['a', 'workspace', 'of', 'people']));

    $shared = navigationSharedProps(14, 7);

    expect(fn (): mixed => assertNavigationContract($shared))
        ->toThrow(ExpectationFailedException::class, '[roster] is shared on every navigation');
});

test('the shared payload stays inside the tripwire', function (int $channels, int $members): void {
    expect(sharedPropBytes(navigationSharedProps($channels, $members)))
        ->toBeLessThanOrEqual(SHARED_PROP_CEILING);
})->with('workspace shapes');

test('the shared payload does not grow with the workspace', function (): void {
    // The pair is the claim, so it is asserted as a pair rather than as two
    // numbers in two tests. Fourteen channels and seven people is the demo;
    // two hundred and three hundred is the workspace the epic was really
    // about, where these same props used to be ~85% of every click.
    $demo = sharedPropBytes(navigationSharedProps(14, 7));
    $atScale = sharedPropBytes(navigationSharedProps(200, 300));

    expect(abs($atScale - $demo))->toBeLessThanOrEqual(
        SHAPE_TOLERANCE,
        "shared props grew from {$demo} B at 14 channels / 7 members to {$atScale} B at 200 / 300; "
        .'a navigation must not scale with the workspace (#1255)',
    );
});

test('marking a channel read is a trip of its own, and carries no roster', function (): void {
    ['viewer' => $viewer, 'from' => $from] = workspaceOfShape(14, 7);

    $visit = $this->actingAs($viewer)->get(channelUrl($from))->assertOk();

    // Half one: the navigation itself does not advance the read pointer, so no
    // paint ever waits on that write. The badge arrives with the page and is
    // cleared afterwards, by a trip of its own.
    $visit->assertInertia(fn (Assert $page): Assert => $page->where("unread.channels.{$from->id}.unread", 1));

    $this->actingAs($viewer)
        ->post(route('channels.read', ['team' => $from->team->slug, 'channel' => $from->slug]))
        ->assertRedirect();

    // Half two: the trip the client makes names only the digest
    // (`UNREAD_DIGEST_PROPS`), so clearing a badge no longer reships the roster
    // it is drawn on. It need not disappear; it must stay this cheap.
    $props = (array) partialRevisit($visit, channelUrl($from), ['unread'])->assertOk()->json('props');

    // The errors bag rides every Inertia response there is, so the roster's
    // absence is the whole of what is left to say.
    expect(array_keys($props))->toBe(['errors', 'unread'])
        ->and($props['unread']['channels'])->not->toHaveKey($from->id)
        ->and($props['unread']['channels'])->toHaveCount(UNREAD_CHANNELS - 1);
});
