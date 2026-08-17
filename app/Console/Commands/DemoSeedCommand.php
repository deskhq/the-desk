<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Carbon\Exceptions\InvalidFormatException;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Thin wrapper that runs {@see DemoSeeder} on demand for a public demo host.
 *
 * Kept separate from `db:seed` (which is dev-only and runs `WorkspaceSeeder`)
 * so the public demo dataset is never pulled into local/testing seeding. The
 * seeder is idempotent, so this doubles as the reset job — re-running it wipes
 * the prior demo team and rebuilds a pristine workspace.
 */
#[Signature('demo:seed {--at= : Pin the clock to this instant while seeding, so the fixture is byte-reproducible}')]
#[Description('Seed (or reset) the public demo workspace')]
final class DemoSeedCommand extends Command
{
    /**
     * Run the demo seeder, wiring the console so its summary output surfaces.
     */
    public function handle(DemoSeeder $seeder): int
    {
        $at = $this->option('at');

        if ($at !== null && ! $this->pinClock((string) $at)) {
            return self::FAILURE;
        }

        try {
            $seeder->setContainer($this->laravel)->setCommand($this)->run();
        } finally {
            // Released whatever happens: a pinned clock that outlived the
            // command would silently back-date every later write in the same
            // process — the scheduled reset job runs alongside other work.
            Carbon::setTestNow();
        }

        return self::SUCCESS;
    }

    /**
     * An absolute ISO 8601 instant: a date, a time, and an explicit zone.
     *
     * Anything looser defeats the point. `Carbon::parse()` happily accepts
     * `tomorrow` (which re-anchors to the wall clock, so nothing is pinned at
     * all) and a bare `2026-06-10` (which resolves against the machine's
     * timezone, so the same command produces different fixtures on different
     * hosts). Both parse, both look like they worked, and both make the capture
     * irreproducible — so neither is accepted.
     */
    private const string INSTANT_PATTERN = '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?(Z|[+-]\d{2}:?\d{2})$/';

    /**
     * Freeze the clock at the given instant, reporting rather than guessing when
     * it is not one.
     *
     * The seeder back-dates its entire narrative from `Carbon::now()`, so a
     * value that silently falls back to the wall clock would produce a fixture
     * that looks right and photographs differently on every run — the exact
     * failure the shell capture's gate exists to catch (see issue #1013).
     */
    private function pinClock(string $at): bool
    {
        if (preg_match(self::INSTANT_PATTERN, $at) !== 1) {
            $this->components->error(sprintf(
                '[%s] is not a valid instant. Pass an absolute ISO 8601 timestamp with a zone, e.g. 2026-06-10T14:30:00Z.',
                $at,
            ));

            return false;
        }

        try {
            Carbon::setTestNow(Carbon::parse($at));
        } catch (InvalidFormatException) {
            $this->components->error(sprintf('[%s] is not a valid instant.', $at));

            return false;
        }

        return true;
    }
}
