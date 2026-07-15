<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\UpdateChecker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Refresh the cached "latest stable release" used by the update-available
 * indicator. Scheduled daily; a no-op when UPDATE_CHECK_ENABLED is false, and
 * silent on any failure so it never breaks the schedule run.
 */
#[Signature('updates:check')]
#[Description('Check GitHub for a newer stable release and cache the result')]
class CheckForUpdatesCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(UpdateChecker $updates): int
    {
        $updates->refresh();

        return self::SUCCESS;
    }
}
