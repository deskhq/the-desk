<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\AuditableActionOccurred;
use App\Support\AuditRecorder;

/**
 * Appends the audit row for an {@see AuditableActionOccurred}. Auto-discovered
 * (the app has no EventServiceProvider), and runs inline so the entry lands in
 * the same request — and the same transaction — as the mutation that caused it.
 *
 * This is the audit counterpart of {@see RecordSecurityEvents}: the one place
 * that turns a domain event into a recorded row.
 */
class RecordAuditActivity
{
    public function __construct(private readonly AuditRecorder $recorder) {}

    /**
     * Handle the event.
     */
    public function handle(AuditableActionOccurred $event): void
    {
        $this->recorder->record(
            $event->team,
            $event->actor,
            $event->action,
            $event->target,
            $event->context,
        );
    }
}
