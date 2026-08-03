<?php

use App\Models\Channel;
use App\Models\Message;
use App\Models\MessagePin;
use App\Models\ScheduledMessage;
use App\Models\Team;
use App\Models\User;
use App\Support\ChannelPage;
use Database\Factories\ChannelMemberFactory;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| What the channel payload costs (#1197)
|--------------------------------------------------------------------------
|
| Every reading on `ChannelPage` is a fixed number of queries by construction —
| the pins, the roster, the receipts and the schedules each cost their own query
| plus their eager loads, whatever they hold. Nothing enforces that but a test
| that counts, so this is the channel page's equivalent of
| `SidebarChannelsQueryCountTest` for the sidebar, and of `MessageLoadSetScopeTest`
| for ADR-0002.
|
| The pin is a constant rather than a comparison against a baseline, so a
| regression that adds a query to *both* sizes is caught as well as one that
| scales with the page's contents.
|
*/

/**
 * Everything a channel render asks the database, with nothing on the page:
 * the membership, the capability gates, the member count, the pins, the roster,
 * the receipts, the schedules, and each reading's eager loads.
 */
const CHANNEL_PAGE_QUERY_BUDGET = 28;

/**
 * Draw every prop group the channel page ships and report the queries it took.
 *
 * @return array{queries: int, page: array<string, mixed>}
 */
function channelPageRender(Channel $channel, User $viewer, Team $team): array
{
    $page = new ChannelPage($channel, $viewer, $team);

    DB::connection()->flushQueryLog();
    DB::connection()->enableQueryLog();

    $drawn = [
        'channel' => $page->channel(),
        'capabilities' => $page->capabilities(),
        'isMember' => $page->isMember(),
        'lastReadMessageId' => $page->lastReadMessageId(),
        'memberCount' => $page->memberCount(),
        'pins' => $page->pins(),
        'roster' => $page->roster(),
        'readers' => $page->readers(),
        'scheduledMessages' => $page->scheduledMessages(),
    ];

    $queries = count(DB::connection()->getQueryLog());

    DB::connection()->disableQueryLog();

    return ['queries' => $queries, 'page' => $drawn];
}

/**
 * Put one more of everything the page lists onto the channel: a member, a
 * pinned message, a read receipt and a pending schedule.
 */
function fillChannelPage(Channel $channel, User $author, int $times): void
{
    for ($i = 0; $i < $times; $i++) {
        $member = teamMemberInChannel($channel);

        $message = Message::factory()->for($channel)->for($author)->create();
        MessagePin::factory()->for($message)->for($channel)->for($member, 'pinnedBy')->create();

        channelMembership(
            $channel,
            $member,
            fn (ChannelMemberFactory $factory): ChannelMemberFactory => $factory->state(['last_read_message_id' => $message->id]),
        );

        ScheduledMessage::factory()->for($channel)->for($author)->create(['reply_to_id' => $message->id]);
    }
}

it('renders the channel payload in a constant number of queries however much the page holds', function (): void {
    ['owner' => $viewer, 'team' => $team, 'channel' => $general] = teamWithChannel();

    fillChannelPage($general, $viewer, 2);

    $small = channelPageRender($general, $viewer, $team);

    fillChannelPage($general, $viewer, 4);

    $large = channelPageRender($general, $viewer, $team);

    expect($small['queries'])->toBe(CHANNEL_PAGE_QUERY_BUDGET)
        ->and($large['queries'])->toBe(CHANNEL_PAGE_QUERY_BUDGET);

    // Sanity-check the renders actually held something, so the counts above are
    // not passing on a page that resolved to nothing.
    expect($small['page']['pins']['pinCount'])->toBe(2)
        ->and($large['page']['pins']['pinCount'])->toBe(6)
        ->and($large['page']['readers'])->toHaveCount(6)
        ->and($large['page']['scheduledMessages'])->toHaveCount(6)
        ->and($large['page']['memberCount'])->toBe(7);
});
