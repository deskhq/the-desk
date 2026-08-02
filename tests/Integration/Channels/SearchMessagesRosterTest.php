<?php

use App\Actions\Channels\OpenDirectMessage;
use App\Actions\Channels\SearchMessages;
use App\Data\MessageSearchCriteria;
use App\Data\MessageSearchHit;
use App\Data\MessageSearchResultData;
use App\Models\Message;
use App\Models\User;
use App\Support\DirectMessageRoster;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| What naming a page of search hits costs (#1113)
|--------------------------------------------------------------------------
|
| A hit in a direct message is labelled with the DM's viewer-relative name,
| which is read off its roster. The search action used to eager-load only
| `channel.team`, so every DM hit lazy-loaded its own membership while the
| thread inbox — asking the identical question — batched them. Both now go
| through `DirectMessageRoster`, and this is what stops that half of the fix
| from being silently deleted: the search action's own tests are HTTP-level and
| would not notice.
|
*/

test('a page of direct-message hits is named without a query per hit', function (): void {
    ['owner' => $viewer, 'team' => $team, 'channel' => $general] = teamWithChannel();

    $counterparts = collect(['Ana Pires', 'Bo Chen', 'Tomas K'])
        ->map(fn (string $name): User => teamMemberInChannel($general, ['name' => $name]));

    $counterparts->each(function (User $counterpart) use ($team, $viewer): void {
        $dm = app(OpenDirectMessage::class)->handle($team, $viewer, $counterpart);
        Message::factory()->for($dm)->for($counterpart, 'user')->create(['body' => 'the quokka danced at dawn']);
    });

    // A group DM is named from the same roster, joined rather than singular, so
    // it rides the identical batch and belongs in the same page.
    $group = app(OpenDirectMessage::class)->openForUsers($team, $viewer, $counterparts->take(2));
    Message::factory()->for($group)->for($counterparts->first(), 'user')->create(['body' => 'the quokka danced at dawn']);

    $hits = app(SearchMessages::class)->handle($viewer, $team, new MessageSearchCriteria(query: 'quokka'));

    DB::connection()->flushQueryLog();
    DB::connection()->enableQueryLog();

    $results = $hits->map(fn (MessageSearchHit $hit): MessageSearchResultData => MessageSearchResultData::fromHit($hit, $viewer));

    expect(DB::connection()->getQueryLog())->toBe([]);

    DB::connection()->disableQueryLog();

    // Sanity-check every hit really was a DM that had to be named from its
    // roster, so the assertion above is not passing on an empty result set.
    expect($results)->toHaveCount(4)
        ->and($results->pluck('isDirectMessage')->unique()->all())->toBe([true])
        ->and($results->pluck('channelName')->sort()->values()->all())
        ->toBe(['Ana Pires', 'Ana Pires, Bo Chen', 'Bo Chen', 'Tomas K']);
});

test('a standard-channel hit is named from the channel itself, with no roster loaded', function (): void {
    ['owner' => $viewer, 'team' => $team, 'channel' => $general] = teamWithChannel();

    Message::factory()->for($general)->for($viewer)->create(['body' => 'the quokka danced at dawn']);

    $hits = app(SearchMessages::class)->handle($viewer, $team, new MessageSearchCriteria(query: 'quokka'));

    expect($hits)->toHaveCount(1)
        ->and($hits->first()->message->channel->relationLoaded('members'))->toBeFalse();

    $result = MessageSearchResultData::fromHit($hits->first(), $viewer);

    expect($result->isDirectMessage)->toBeFalse()
        ->and($result->channelName)->toBe($general->name);
});

test('the roster loader stays silent when there is no direct message among the page', function (): void {
    ['channel' => $general] = teamWithChannel();

    DB::connection()->flushQueryLog();
    DB::connection()->enableQueryLog();

    DirectMessageRoster::load(new Collection([$general]));
    DirectMessageRoster::load([]);

    expect(DB::connection()->getQueryLog())->toBe([]);

    DB::connection()->disableQueryLog();

    expect($general->relationLoaded('members'))->toBeFalse();
});

test('the roster loader batches a channel named more than once into one load', function (): void {
    ['owner' => $viewer, 'team' => $team, 'channel' => $general] = teamWithChannel();

    $counterpart = teamMemberInChannel($general);
    $dm = app(OpenDirectMessage::class)->handle($team, $viewer, $counterpart);

    $messages = Message::factory()->for($dm)->for($counterpart, 'user')->count(3)->create();
    $messages = Message::query()->whereKey($messages->modelKeys())->with('channel')->get();

    DB::connection()->flushQueryLog();
    DB::connection()->enableQueryLog();

    DirectMessageRoster::loadForMessages($messages);

    expect(DB::connection()->getQueryLog())->toHaveCount(1);

    DB::connection()->disableQueryLog();

    expect($messages->every(fn (Message $message): bool => $message->channel->relationLoaded('members')))->toBeTrue();
});
