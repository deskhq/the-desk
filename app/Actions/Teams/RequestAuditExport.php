<?php

declare(strict_types=1);

namespace App\Actions\Teams;

use App\Enums\AuditAction;
use App\Enums\AuditExportFormat;
use App\Enums\AuditExportLogType;
use App\Enums\AuditExportStatus;
use App\Events\AuditableActionOccurred;
use App\Jobs\GenerateAuditExport;
use App\Models\AuditExport;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Throwable;

/**
 * Queues an export of one of a workspace's admin logs.
 *
 * Exporting a log is itself an audited workspace action — it takes a copy of
 * who did what out of the app — so the entry is recorded here, alongside the
 * request that caused it.
 */
class RequestAuditExport
{
    /**
     * @throws Throwable when the export could not be queued; the row is marked
     *                   failed first, so it never blocks the team's next request.
     */
    public function handle(
        Team $team,
        User $actor,
        AuditExportLogType $logType,
        AuditExportFormat $format,
        ?string $rangeStart,
        ?string $rangeEnd,
    ): AuditExport {
        /** @var AuditExport $export */
        $export = $team->auditExports()->create([
            'requested_by' => $actor->id,
            'log_type' => $logType,
            'format' => $format,
            'range_start' => $rangeStart,
            'range_end' => $rangeEnd,
            'status' => AuditExportStatus::Pending,
        ]);

        // Dispatch eagerly so a queue-push failure surfaces to the caller rather
        // than leaving a pending row that would block the team's next request
        // forever (a pending export never reaches the retention purge).
        try {
            Bus::dispatch(new GenerateAuditExport($export->id));
        } catch (Throwable $exception) {
            $export->update(['status' => AuditExportStatus::Failed]);

            throw $exception;
        }

        event(new AuditableActionOccurred($team, $actor, AuditAction::AuditExported, $export, [
            'log' => $logType->label(),
            'format' => $format->label(),
            'range' => $this->rangeLabel($rangeStart, $rangeEnd),
        ]));

        return $export;
    }

    /**
     * Build the human range label recorded on the export's audit entry.
     */
    private function rangeLabel(?string $rangeStart, ?string $rangeEnd): string
    {
        if ($rangeStart === null && $rangeEnd === null) {
            return __('All time');
        }

        if ($rangeStart === null) {
            return sprintf(__('Until %s'), $rangeEnd);
        }

        if ($rangeEnd === null) {
            return sprintf(__('From %s'), $rangeStart);
        }

        return sprintf('%s – %s', $rangeStart, $rangeEnd);
    }
}
