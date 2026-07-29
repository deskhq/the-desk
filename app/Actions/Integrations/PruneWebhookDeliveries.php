<?php

declare(strict_types=1);

namespace App\Actions\Integrations;

use App\Models\WebhookDelivery;

class PruneWebhookDeliveries
{
    /**
     * Delete delivery attempts that have outlived the configured retention
     * window.
     *
     * Every attempt — including each retry — appends a row carrying the full
     * envelope it POSTed, so an active workspace accumulates both volume and a
     * copy of the message data that left it. Pruning is therefore both a
     * disk-space sweep and the data-minimisation half of the retention promise.
     *
     * A window of zero or less means "keep forever" — an explicit opt-out for a
     * deployment that enforces retention at the database or backup layer.
     *
     * @return int the number of attempts pruned
     */
    public function handle(): int
    {
        $days = (int) config('integrations.webhooks.retention_days');

        if ($days < 1) {
            return 0;
        }

        return WebhookDelivery::query()
            ->where('created_at', '<', now()->subDays($days))
            ->delete();
    }
}
