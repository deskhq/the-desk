<?php

namespace App\Http\Controllers\Teams;

use App\Data\AuditExportData;
use App\Enums\AuditAction;
use App\Enums\AuditExportFormat;
use App\Enums\AuditExportLogType;
use App\Enums\AuditExportStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\RequestAuditExportRequest;
use App\Jobs\GenerateAuditExport;
use App\Models\AuditExport;
use App\Models\Team;
use App\Support\AuditRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AuditExportController extends Controller
{
    /**
     * Show the workspace's audit exports: the request form and recent exports,
     * newest first.
     *
     * The page exports both admin-only logs, so it is open to anyone who can view
     * at least one of them; each individual request and download re-checks the
     * policy for its specific log.
     */
    public function index(Request $request, Team $team): Response
    {
        abort_unless(
            Gate::allows('viewAudit', $team) || Gate::allows('viewSecurityLog', $team),
            403,
        );

        $exports = $team->auditExports()
            ->with('requester')
            ->latest()
            ->orderByDesc('id')
            ->get()
            ->map(fn (AuditExport $export): AuditExportData => AuditExportData::fromExport($export))
            ->all();

        return Inertia::render('teams/AuditExports', [
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'slug' => $team->slug,
            ],
            'exports' => $exports,
            'logTypeOptions' => AuditExportLogType::options(),
            'formatOptions' => AuditExportFormat::options(),
        ]);
    }

    /**
     * Queue a fresh export of one of the workspace's logs.
     *
     * Only one export may be generating per team at a time; a second request is
     * rejected with a toast until the first finishes.
     */
    public function store(RequestAuditExportRequest $request, Team $team, AuditRecorder $recorder): RedirectResponse
    {
        if ($team->auditExports()->where('status', AuditExportStatus::Pending)->exists()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('An export is already generating')]);

            return back();
        }

        $logType = AuditExportLogType::from((string) $request->validated('log_type'));
        $format = AuditExportFormat::from((string) $request->validated('format'));
        $rangeStart = $request->validated('range_start');
        $rangeEnd = $request->validated('range_end');

        $export = $team->auditExports()->create([
            'requested_by' => $request->user()->id,
            'log_type' => $logType,
            'format' => $format,
            'range_start' => $rangeStart,
            'range_end' => $rangeEnd,
            'status' => AuditExportStatus::Pending,
        ]);

        // Dispatch eagerly so a queue-push failure surfaces here rather than
        // leaving a pending row that would block the team's next request forever
        // (a pending export never reaches the retention purge).
        try {
            Bus::dispatch(new GenerateAuditExport($export->id));
        } catch (Throwable $exception) {
            $export->update(['status' => AuditExportStatus::Failed]);
            report($exception);

            Inertia::flash('toast', ['type' => 'error', 'message' => __('Could not start the export. Please try again.')]);

            return back();
        }

        $recorder->record($team, $request->user(), AuditAction::AuditExported, $export, [
            'log' => $logType->label(),
            'format' => $format->label(),
            'range' => $this->rangeLabel($rangeStart, $rangeEnd),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __("Preparing your export. We'll email you when it's ready.")]);

        return back();
    }

    /**
     * Download a ready export file.
     *
     * Guards the team scope, re-checks the policy for the export's log type for
     * the current user (any current admin may download, not only the requester),
     * and enforces the download window: a pending, failed, or expired export is a
     * 404, and one belonging to another team is a 404.
     */
    public function download(Request $request, Team $team, AuditExport $auditExport): StreamedResponse
    {
        abort_unless($auditExport->team_id === $team->id, 404);

        Gate::authorize(
            $auditExport->log_type === AuditExportLogType::Security ? 'viewSecurityLog' : 'viewAudit',
            $team,
        );

        abort_unless($auditExport->isReady() && ! $auditExport->isExpired(), 404);

        $filename = $auditExport->log_type->value.'-export.'.$auditExport->format->extension();

        return Storage::disk(GenerateAuditExport::DISK)->download($auditExport->path, $filename);
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
