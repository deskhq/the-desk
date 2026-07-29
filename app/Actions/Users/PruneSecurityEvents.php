<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Models\SecurityEvent;

class PruneSecurityEvents
{
    /**
     * Delete security events that have outlived the configured retention window.
     *
     * Every sign-in, credential change, and session revocation appends a row, and
     * each carries an IP address and User-Agent, so this is a data-minimisation
     * control an auditor asks about — not merely a disk-space sweep.
     *
     * Deliberately a query-builder bulk delete: {@see SecurityEvent} rejects a
     * delete through an Eloquent instance to keep the log append-only, and a bulk
     * delete fires no model events, which is the sanctioned path for pruning.
     *
     * A window of zero or less means "keep forever" — an explicit opt-out for a
     * deployment that enforces retention at the database or backup layer instead.
     *
     * @return int the number of events pruned
     */
    public function handle(): int
    {
        $days = (int) config('security.events.retention_days');

        if ($days < 1) {
            return 0;
        }

        return SecurityEvent::query()
            ->where('created_at', '<', now()->subDays($days))
            ->delete();
    }
}
