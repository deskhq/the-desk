<?php

namespace App\Console\Commands;

use App\Models\Message;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Meilisearch\Client;
use Meilisearch\Exceptions\ApiException;

/**
 * Rebuild the Meilisearch search index when it is empty.
 *
 * Meilisearch's on-disk format changes between versions, so the production
 * stack pins the index to a version-scoped named volume; bumping the pinned
 * tag rotates the volume and Meilisearch boots against an empty data directory.
 * The search index is derived data (the database is the source of truth), so
 * this command repopulates it from the database instead of running a
 * dump-based Meilisearch migration. It runs on boot and is a no-op once the
 * index is already populated.
 */
#[Signature('search:sync')]
#[Description('Import searchable models into Meilisearch when the index is empty (e.g. after a volume reset)')]
class SearchIndexSyncCommand extends Command
{
    /**
     * Searchable models to keep in sync with the search index.
     *
     * @var list<class-string<Message>>
     */
    private const SEARCHABLE_MODELS = [
        Message::class,
    ];

    /**
     * Execute the console command.
     */
    public function handle(Client $meilisearch): int
    {
        if (config('scout.driver') !== 'meilisearch') {
            $this->info('Search driver is not Meilisearch; nothing to sync.');

            return self::SUCCESS;
        }

        foreach (self::SEARCHABLE_MODELS as $model) {
            $this->syncModel($meilisearch, $model);
        }

        return self::SUCCESS;
    }

    /**
     * Import the model when its index is empty but the database has records.
     *
     * @param  class-string<Message>  $model
     */
    private function syncModel(Client $meilisearch, string $model): void
    {
        $indexed = $this->indexedDocumentCount($meilisearch, (new $model)->searchableAs());

        if ($indexed > 0) {
            $this->info("[{$model}] index already holds {$indexed} document(s); skipping.");

            return;
        }

        $pending = $model::query()->count();

        if ($pending === 0) {
            $this->info("[{$model}] has no records to index; skipping.");

            return;
        }

        $this->info("[{$model}] index is empty; importing {$pending} record(s)...");
        $model::makeAllSearchable();
    }

    /**
     * Number of documents currently held in the model's Meilisearch index.
     *
     * A fresh volume has no index yet, which Meilisearch reports as a 404;
     * treat that (and any missing counter) as an empty index.
     */
    private function indexedDocumentCount(Client $meilisearch, string $index): int
    {
        try {
            $stats = $meilisearch->index($index)->stats();
        } catch (ApiException) {
            return 0;
        }

        return (int) ($stats['numberOfDocuments'] ?? 0);
    }
}
