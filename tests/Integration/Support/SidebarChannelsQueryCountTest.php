<?php

use App\Actions\Channels\OpenDirectMessage;
use App\Data\ChannelData;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use App\Support\SidebarChannels;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| What the sidebar payload costs (#1113)
|--------------------------------------------------------------------------
|
| `SidebarChannels::query()` is one query by construction, but the list it feeds
| used to re-enter the database once or twice per direct message to work out
| what that DM is called for the viewer — on every workspace request. The
| batched roster load is the contract that stops it, and a contract about query
| counts is only kept if something counts them: this is the sidebar's ADR-0011
| equivalent of `MessageLoadSetScopeTest` for ADR-0002.
|
| The pin is a constant, not a comparison against a baseline, so a regression
| that adds a query to *both* sizes is caught as well as one that scales.
|
*/

/**
 * The list query and the one batched roster load — the whole cost of a render,
 * whatever the list holds.
 */
const SIDEBAR_QUERY_BUDGET = 2;

/**
 * Run one full sidebar render and report the queries it took.
 *
 * @return array{queries: int, rows: array<int, ChannelData>}
 */
function sidebarRender(User $viewer, Team $team): array
{
    DB::connection()->flushQueryLog();
    DB::connection()->enableQueryLog();

    $rows = new SidebarChannels($viewer, $team)->forSidebar();

    $queries = count(DB::connection()->getQueryLog());

    DB::connection()->disableQueryLog();

    return ['queries' => $queries, 'rows' => $rows];
}

/**
 * Open a 1:1 and a group direct message the viewer will see listed, both with a
 * message in them so they earn their row without an active-channel override.
 */
function listedDirectMessages(Team $team, User $viewer, User $first, User $second): void
{
    $direct = app(OpenDirectMessage::class)->handle($team, $viewer, $first);
    $group = app(OpenDirectMessage::class)->openForUsers($team, $viewer, collect([$first, $second]));

    Message::factory()->for($direct)->for($first, 'user')->create();
    Message::factory()->for($group)->for($second, 'user')->create();
}

it('renders the sidebar in a constant number of queries however many direct messages it lists', function (): void {
    ['owner' => $viewer, 'team' => $team, 'channel' => $general] = teamWithChannel();

    $mates = collect(range(1, 6))->map(fn (): User => teamMemberInChannel($general));

    listedDirectMessages($team, $viewer, $mates[0], $mates[1]);

    $small = sidebarRender($viewer, $team);

    listedDirectMessages($team, $viewer, $mates[2], $mates[3]);
    listedDirectMessages($team, $viewer, $mates[4], $mates[5]);

    $large = sidebarRender($viewer, $team);

    expect($small['queries'])->toBe(SIDEBAR_QUERY_BUDGET)
        ->and($large['queries'])->toBe(SIDEBAR_QUERY_BUDGET);

    // Sanity-check the renders actually named their DMs, so the counts above are
    // not passing on rows that resolved to nothing.
    expect(collect($small['rows'])->where('isDirect', true))->toHaveCount(2)
        ->and(collect($large['rows'])->where('isDirect', true))->toHaveCount(6)
        ->and(collect($large['rows'])->where('isDirect', true)->pluck('name')->filter()->all())->toHaveCount(6);
});

it('renders a sidebar of standard channels without loading a single membership', function (): void {
    ['owner' => $viewer, 'team' => $team, 'channel' => $general] = teamWithChannel();

    $channels = Channel::factory()->count(4)->for($team)->create(['created_by' => $viewer->id]);
    $channels->each(fn (Channel $channel) => $channel->members()->attach($viewer->id));

    $render = sidebarRender($viewer, $team);

    // No DM in the list means no roster to batch, so the load is skipped and
    // the render costs the list query alone rather than an empty second one.
    expect($render['queries'])->toBe(1)
        ->and($render['rows'])->toHaveCount($channels->count() + 1)
        ->and(collect($render['rows'])->pluck('name')->filter()->all())->toHaveCount($channels->count() + 1);
});
