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
class DemoSeedCommand extends Command
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
     * Freeze the clock at the given instant, reporting rather than guessing when
     * it cannot be parsed.
     *
     * The seeder back-dates its entire narrative from `Carbon::now()`, so an
     * unparseable value silently falling back to the wall clock would produce a
     * fixture that looks right and photographs differently on every run — the
     * exact failure the shell capture's gate exists to catch (see issue #1013).
     */
    private function pinClock(string $at): bool
    {
        try {
            Carbon::setTestNow(Carbon::parse($at));
        } catch (InvalidFormatException) {
            $this->components->error(sprintf('[%s] is not a valid instant.', $at));

            return false;
        }

        return true;
    }
}
