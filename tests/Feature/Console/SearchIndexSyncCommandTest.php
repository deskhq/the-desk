<?php

use App\Models\Message;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Queue;
use Laravel\Scout\Jobs\MakeSearchable;
use Meilisearch\Client;
use Meilisearch\Endpoints\Indexes;
use Meilisearch\Exceptions\ApiException;

/**
 * Bind a fake Meilisearch client whose `messages` index reports the given
 * document count, so the command can decide whether a reindex is needed
 * without talking to a real Meilisearch instance.
 */
function fakeMeilisearchDocumentCount(int $documents): void
{
    $index = Mockery::mock(Indexes::class);
    $index->shouldReceive('stats')->andReturn(['numberOfDocuments' => $documents]);

    test()->mock(Client::class, function ($mock) use ($index): void {
        $mock->shouldReceive('index')->with('messages')->andReturn($index);
    });
}

it('does nothing when the search driver is not meilisearch', function (): void {
    config()->set('scout.driver', 'collection');
    Queue::fake();

    $this->artisan('search:sync')
        ->expectsOutputToContain('not Meilisearch')
        ->assertSuccessful();

    Queue::assertNothingPushed();
});

it('imports records when the meilisearch index is empty', function (): void {
    config()->set('scout.driver', 'meilisearch');
    Queue::fake();
    fakeMeilisearchDocumentCount(0);

    Message::withoutSyncingToSearch(fn () => Message::factory()->count(3)->create());

    $this->artisan('search:sync')->assertSuccessful();

    Queue::assertPushed(MakeSearchable::class);
});

it('imports when the meilisearch index does not exist yet', function (): void {
    config()->set('scout.driver', 'meilisearch');
    Queue::fake();

    $index = Mockery::mock(Indexes::class);
    $index->shouldReceive('stats')->andThrow(new ApiException(
        new Response(404, [], (string) json_encode(['code' => 'index_not_found'])),
        ['code' => 'index_not_found'],
    ));
    $this->mock(Client::class, fn ($mock) => $mock->shouldReceive('index')->andReturn($index));

    Message::withoutSyncingToSearch(fn () => Message::factory()->count(2)->create());

    $this->artisan('search:sync')->assertSuccessful();

    Queue::assertPushed(MakeSearchable::class);
});

it('skips import when the meilisearch index is already populated', function (): void {
    config()->set('scout.driver', 'meilisearch');
    Queue::fake();
    fakeMeilisearchDocumentCount(5);

    Message::withoutSyncingToSearch(fn () => Message::factory()->count(3)->create());

    $this->artisan('search:sync')->assertSuccessful();

    Queue::assertNotPushed(MakeSearchable::class);
});

it('skips import when there are no records to index', function (): void {
    config()->set('scout.driver', 'meilisearch');
    Queue::fake();
    fakeMeilisearchDocumentCount(0);

    $this->artisan('search:sync')->assertSuccessful();

    Queue::assertNotPushed(MakeSearchable::class);
});
