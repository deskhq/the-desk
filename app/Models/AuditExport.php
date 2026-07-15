<?php

namespace App\Models;

use App\Enums\AuditExportFormat;
use App\Enums\AuditExportLogType;
use App\Enums\AuditExportStatus;
use App\Jobs\GenerateAuditExport;
use Database\Factories\AuditExportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An admin-requested export of one of a workspace's append-only logs (the audit
 * log or the security-event log), assembled asynchronously by
 * {@see GenerateAuditExport} and served, once ready, from a private disk to any
 * current team admin for a limited window (compliance evidence export).
 *
 * @property string $id
 * @property string $team_id
 * @property string|null $requested_by
 * @property AuditExportLogType $log_type
 * @property AuditExportFormat $format
 * @property Carbon|null $range_start
 * @property Carbon|null $range_end
 * @property AuditExportStatus $status
 * @property string|null $path
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read User|null $requester
 */
#[Fillable(['team_id', 'requested_by', 'log_type', 'format', 'range_start', 'range_end', 'status', 'path', 'expires_at'])]
class AuditExport extends Model
{
    /** @use HasFactory<AuditExportFactory> */
    use HasFactory, HasUuids;

    /**
     * Get the workspace the export belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the admin who requested the export.
     *
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * Determine if the file has been built and is ready to download.
     */
    public function isReady(): bool
    {
        return $this->status === AuditExportStatus::Ready && $this->path !== null;
    }

    /**
     * Determine if the download window has closed.
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'log_type' => AuditExportLogType::class,
            'format' => AuditExportFormat::class,
            'range_start' => 'date',
            'range_end' => 'date',
            'status' => AuditExportStatus::class,
            'expires_at' => 'datetime',
        ];
    }
}
