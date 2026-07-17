<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Print the version this instance is running, and nothing else.
 *
 * The version lives in the committed VERSION file and is read (and stripped of
 * its release-please annotation) by config/app.php, so this command reports
 * that resolved value rather than re-implementing the parsing elsewhere.
 * Output is bare and newline-terminated so docker/upgrade.sh can capture it
 * with $(...) and compare it against the version it just deployed: a 200 from
 * /up proves liveness, not identity.
 */
#[Signature('app:version')]
#[Description('Print the version this instance is running')]
class AppVersionCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->line((string) config('app.version'));

        return self::SUCCESS;
    }
}
