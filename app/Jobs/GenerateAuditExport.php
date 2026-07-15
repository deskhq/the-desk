<?php

namespace App\Jobs;

use App\Enums\AuditAction;
use App\Enums\AuditExportFormat;
use App\Enums\AuditExportLogType;
use App\Enums\AuditExportStatus;
use App\Mail\AuditExportReady;
use App\Models\AuditActivity;
use App\Models\AuditExport;
use App\Models\SecurityEvent;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Throwable;

class GenerateAuditExport implements ShouldQueue
{
    use Queueable;

    /**
     * The private disk the export file is written to.
     */
    public const string DISK = 'local';

    /**
     * The directory on the private disk export files live under.
     */
    private const string DIRECTORY = 'audit-exports';

    /**
     * How many days the built file stays downloadable before it is purged.
     */
    public const int RETENTION_DAYS = 7;

    public function __construct(private string $auditExportId) {}

    /**
     * Assemble the requested log into a single CSV or JSON file on the private
     * disk, then mark the export ready and email the requester.
     *
     * Re-fetches by id and bails quietly when the export is gone (the team or
     * requester may have been deleted since the job was queued).
     */
    public function handle(): void
    {
        $export = AuditExport::with(['team', 'requester'])->find($this->auditExportId);

        if ($export === null) {
            return;
        }

        $path = self::DIRECTORY.'/'.$export->id.'.'.$export->format->extension();
        $disk = Storage::disk(self::DISK);
        $disk->makeDirectory(self::DIRECTORY);

        $records = $this->records($export);

        $disk->put($path, $export->format === AuditExportFormat::Csv
            ? $this->toCsv($export->log_type, $records)
            : $this->toJson($records));

        $export->update([
            'status' => AuditExportStatus::Ready,
            'path' => $path,
            'expires_at' => now()->addDays(self::RETENTION_DAYS),
        ]);

        // The file is the deliverable; a failed notification must not undo the
        // ready export or trip the job's failed() handler. Skip it entirely when
        // the requester's account was deleted between request and generation.
        if ($export->requester !== null) {
            try {
                Mail::to($export->requester)->send(new AuditExportReady($export));
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    /**
     * Mark the export failed so the exports page can offer a retry.
     */
    public function failed(Throwable $exception): void
    {
        AuditExport::whereKey($this->auditExportId)->update(['status' => AuditExportStatus::Failed]);
    }

    /**
     * Build the ordered records for the requested log, within the optional range.
     *
     * @return array<int, array<string, mixed>>
     */
    private function records(AuditExport $export): array
    {
        return $export->log_type === AuditExportLogType::Audit
            ? $this->auditRecords($export)
            : $this->securityRecords($export);
    }

    /**
     * Build the audit-log records: every entry scoped to the team, oldest first.
     *
     * @return array<int, array<string, mixed>>
     */
    private function auditRecords(AuditExport $export): array
    {
        return AuditActivity::query()
            ->where('team_id', $export->team_id)
            ->when($this->rangeStart($export), fn (Builder $query, Carbon $start) => $query->where('created_at', '>=', $start))
            ->when($this->rangeEnd($export), fn (Builder $query, Carbon $end) => $query->where('created_at', '<=', $end))
            ->with('causer')->oldest()
            ->orderBy('id')
            ->get()
            ->map(function (AuditActivity $activity): array {
                $action = AuditAction::from((string) $activity->event);

                /** @var array<string, mixed> $properties */
                $properties = $activity->properties?->toArray() ?? [];

                /** @var User|null $actor */
                $actor = $activity->causer;

                return [
                    'id' => $activity->id,
                    'occurred_at' => $this->timestamp($activity->created_at),
                    'action' => $action->value,
                    'action_label' => $action->label(),
                    'actor_name' => $actor?->name,
                    'actor_id' => $activity->causer_id,
                    'description' => $action->describe($properties),
                    'properties' => $properties,
                ];
            })
            ->all();
    }

    /**
     * Build the security-event records: the current members' account-level events
     * scoped via the same live membership join the on-screen log uses, oldest
     * first.
     *
     * @return array<int, array<string, mixed>>
     */
    private function securityRecords(AuditExport $export): array
    {
        return SecurityEvent::query()
            ->whereIn('user_id', $this->memberIds($export->team))
            ->when($this->rangeStart($export), fn (Builder $query, Carbon $start) => $query->where('created_at', '>=', $start))
            ->when($this->rangeEnd($export), fn (Builder $query, Carbon $end) => $query->where('created_at', '<=', $end))
            ->with('user')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (SecurityEvent $event): array => [
                'id' => $event->id,
                'occurred_at' => $this->timestamp($event->created_at),
                'type' => $event->type->value,
                'type_label' => $event->type->label(),
                'actor_name' => $event->user->name,
                'actor_id' => $event->user_id,
                'ip_address' => $event->ip_address,
                'user_agent' => $event->user_agent,
                'is_new_device' => $event->is_new_device,
            ])
            ->all();
    }

    /**
     * A subquery selecting the ids of the team's current members, matching the
     * security-log view's scoping exactly.
     */
    private function memberIds(Team $team): Builder
    {
        return $team->members()->getQuery()->select('users.id');
    }

    /**
     * Resolve the inclusive start of the range as a UTC instant, interpreting the
     * whole day in the requester's timezone. Null when the range is unbounded.
     */
    private function rangeStart(AuditExport $export): ?Carbon
    {
        if ($export->range_start === null) {
            return null;
        }

        return Carbon::parse($export->range_start->toDateString(), $this->timezone($export))->startOfDay()->utc();
    }

    /**
     * Resolve the inclusive end of the range as a UTC instant, interpreting the
     * whole day in the requester's timezone. Null when the range is unbounded.
     */
    private function rangeEnd(AuditExport $export): ?Carbon
    {
        if ($export->range_end === null) {
            return null;
        }

        return Carbon::parse($export->range_end->toDateString(), $this->timezone($export))->endOfDay()->utc();
    }

    /**
     * The timezone the requester's whole-day range bounds are interpreted in.
     */
    private function timezone(AuditExport $export): string
    {
        return $export->requester?->timezone ?: 'UTC';
    }

    /**
     * Format a timestamp as a UTC ISO-8601 string with a `Z` suffix.
     */
    private function timestamp(?CarbonInterface $at): ?string
    {
        return $at?->utc()->toIso8601ZuluString();
    }

    /**
     * Render the records as a CSV document with a header row keyed to the log.
     *
     * @param  array<int, array<string, mixed>>  $records
     */
    private function toCsv(AuditExportLogType $logType, array $records): string
    {
        $columns = $this->columns($logType);

        $lines = [$this->csvRow($columns)];

        foreach ($records as $record) {
            $lines[] = $this->csvRow(array_map(fn (string $column): string => $this->cell($record[$column]), $columns));
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Render one CSV row, quoting only the fields that need it (RFC 4180).
     *
     * @param  array<int, string>  $values
     */
    private function csvRow(array $values): string
    {
        return implode(',', array_map($this->csvField(...), $values));
    }

    /**
     * Escape a single CSV field. A leading =, +, -, or @ is neutralised with a
     * prefixed apostrophe so a spreadsheet cannot treat exported log data as a
     * formula (CSV injection); the field is then wrapped in quotes with embedded
     * quotes doubled when it carries a comma, quote, or newline.
     */
    private function csvField(string $value): string
    {
        if (preg_match('/^[=+\-@]/', $value) === 1) {
            $value = "'".$value;
        }

        if (preg_match('/[",\r\n]/', $value) === 1) {
            return '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }

    /**
     * Render the records as a pretty-printed JSON document.
     *
     * @param  array<int, array<string, mixed>>  $records
     */
    private function toJson(array $records): string
    {
        return (string) json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * The ordered column names for the given log's CSV.
     *
     * @return array<int, string>
     */
    private function columns(AuditExportLogType $logType): array
    {
        return $logType === AuditExportLogType::Audit
            ? ['id', 'occurred_at', 'action', 'action_label', 'actor_name', 'actor_id', 'description', 'properties']
            : ['id', 'occurred_at', 'type', 'type_label', 'actor_name', 'actor_id', 'ip_address', 'user_agent', 'is_new_device'];
    }

    /**
     * Render a record value for a single CSV cell: nested data as compact JSON,
     * booleans as `true`/`false`, and a null as an empty cell.
     */
    private function cell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return (string) $value;
    }
}
