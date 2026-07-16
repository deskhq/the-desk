<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\DemoSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Thin wrapper that runs {@see DemoSeeder} on demand for a public demo host.
 *
 * Kept separate from `db:seed` (which is dev-only and runs `WorkspaceSeeder`)
 * so the public demo dataset is never pulled into local/testing seeding. The
 * seeder is idempotent, so this doubles as the reset job — re-running it wipes
 * the prior demo team and rebuilds a pristine workspace.
 */
#[Signature('demo:seed')]
#[Description('Seed (or reset) the public demo workspace')]
class DemoSeedCommand extends Command
{
    /**
     * Run the demo seeder, wiring the console so its summary output surfaces.
     */
    public function handle(DemoSeeder $seeder): int
    {
        $seeder->setContainer($this->laravel)->setCommand($this)->run();

        return self::SUCCESS;
    }
}
