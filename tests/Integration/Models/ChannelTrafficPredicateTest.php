<?php

declare(strict_types=1);

use App\Models\Message;
use App\Support\SidebarChannels;

/*
|--------------------------------------------------------------------------
| The channel-traffic predicate, server half (#1114)
|--------------------------------------------------------------------------
|
| "A thread-only reply is not ordinary channel traffic" is one rule with two
| server forms — `Message::channelTraffic()` in SQL, and
| `Message::isChannelTraffic()` for a payload already in memory — and one client
| twin in `resources/js/lib/channelTraffic.ts`.
|
| All three are proven against the *same* table of cases,
| `tests/Fixtures/channel-traffic-cases.json`, which the sibling
| `resources/js/lib/channelTraffic.test.ts` reads too. That shared table is the
| parity: a change to what counts as channel traffic made in one language and
| not the other turns the other language's run red, which is exactly what seven
| hand-written copies could not do.
|
*/

/**
 * The shared case table, decoded.
 *
 * @return array<int, array{name: string, threadRootId: string|null, sentToChannel: bool, isChannelTraffic: bool}>
 */
function channelTrafficCases(): array
{
    return json_decode(
        (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/channel-traffic-cases.json'),
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );
}

test('the case table is exhaustive over the two facts the rule reads', function (): void {
    $combinations = array_map(
        fn (array $case): string => ($case['threadRootId'] === null ? 'top-level' : 'reply')
            .'/'.($case['sentToChannel'] ? 'flagged' : 'unflagged'),
        channelTrafficCases(),
    );

    // The rule reads exactly two booleans, so anything short of all four
    // combinations leaves a branch neither language is pinned on.
    expect(array_unique($combinations))->toHaveCount(4);
});

test('the scope keeps exactly the messages the case table calls channel traffic', function (): void {
    ['owner' => $author, 'channel' => $channel] = teamWithChannel();

    $root = Message::factory()->for($channel)->for($author)->create();

    // The root itself is top-level, so it is channel traffic too.
    $expected = [$root->id];

    foreach (channelTrafficCases() as $case) {
        $message = Message::factory()->for($channel)->for($author)->create([
            'thread_root_id' => $case['threadRootId'] === null ? null : $root->id,
            'sent_to_channel' => $case['sentToChannel'],
        ]);

        if ($case['isChannelTraffic']) {
            $expected[] = $message->id;
        }
    }

    expect(Message::query()->channelTraffic()->pluck('id')->sort()->values()->all())
        ->toBe(collect($expected)->sort()->values()->all());
});

test('the in-memory twin answers the case table the way the scope does', function (array $case): void {
    expect(Message::isChannelTraffic($case['threadRootId'], $case['sentToChannel']))
        ->toBe($case['isChannelTraffic']);
})->with(function (): array {
    $sets = [];

    foreach (channelTrafficCases() as $case) {
        $sets[$case['name']] = [$case];
    }

    return $sets;
});

/**
 * The scope is correlated against the outer query in {@see SidebarChannels},
 * where `channels` and `channel_members` are joined in, so an unqualified column
 * would be ambiguous the day either table grew one of the same name. Pinned here
 * rather than left to the sidebar's own tests, which would only fail on that day.
 */
test('the scope names its columns against the messages table', function (): void {
    expect(Message::query()->channelTraffic()->toRawSql())
        ->toContain('messages.thread_root_id')
        ->toContain('messages.sent_to_channel');
});
