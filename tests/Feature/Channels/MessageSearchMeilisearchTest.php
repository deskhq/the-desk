<?php

use App\Actions\Channels\SearchMessages;
use App\Actions\Teams\CreateTeam;
use App\Data\MessageSearchCriteria;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Support\MessageSnippet;
use Laravel\Scout\Builder as ScoutBuilder;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\Engine;

/**
 * The production Meilisearch path returns a highlighted, cropped `_formatted.body`
 * and can pre-filter the candidate window by the indexed `created_at` timestamp —
 * neither of which the `collection` driver exercises. This drives the action
 * against a faked Meilisearch engine so both branches are proven without a live
 * server, per the epic's locked "one focused unit test with a faked hit".
 */
test('a meilisearch _formatted body becomes the snippet and dates pre-filter the engine', function (): void {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();
    $member = User::factory()->create();
    $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);
    $general->channelMembers()->firstOrCreate(['user_id' => $member->id]);

    // Created under the collection driver; the raw HTML in the body proves the
    // engine's formatted value is escaped before its sentinels become <mark>.
    $message = Message::factory()->for($general)->for($owner)->create([
        'body' => 'the raw <b>body</b> zephyr text',
    ]);

    $captured = null;
    $engine = Mockery::mock(Engine::class);
    $engine->shouldReceive('search')->andReturnUsing(function (ScoutBuilder $builder) use (&$captured, $message): array {
        $captured = $builder;

        return ['hits' => [[
            'id' => $message->id,
            '_formatted' => ['body' => 'the raw <b>body</b> '.MessageSnippet::HIGHLIGHT_PRE_TAG.'zephyr'.MessageSnippet::HIGHLIGHT_POST_TAG.' text'],
        ]]];
    });

    config(['scout.driver' => 'meilisearch']);
    app(EngineManager::class)->extend('meilisearch', fn (): Engine => $engine);

    $criteria = new MessageSearchCriteria(
        query: 'zephyr',
        after: now()->subWeek(),
        before: now()->addDay(),
    );

    $hits = app(SearchMessages::class)->handle($member, $team, $criteria);

    expect($hits)->toHaveCount(1)
        ->and($hits->first()->snippet)->toBe('the raw &lt;b&gt;body&lt;/b&gt; <mark>zephyr</mark> text');

    // The date facets reached the engine as created_at pre-filter bounds.
    $dateWheres = collect($captured->wheres)->where('field', 'created_at');
    expect($dateWheres->pluck('operator')->all())->toBe(['>=', '<=']);
});
