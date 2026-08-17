<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DataExportStatus;
use App\Jobs\ExportUserData;
use Database\Factories\DataExportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A user-requested archive of their personal data, assembled asynchronously by
 * {@see ExportUserData} and served, once ready, from a private disk
 * for a limited window (GDPR self-service export).
 *
 * @property string $id
 * @property string $user_id
 * @property DataExportStatus $status
 * @property string|null $path
 * @property int|null $size_bytes
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'status', 'path', 'size_bytes', 'expires_at'])]
final class DataExport extends Model
{
    /** @use HasFactory<DataExportFactory> */
    use HasFactory, HasUuids;

    /**
     * Get the user the export belongs to.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Determine if the archive has been built and is ready to download.
     */
    public function isReady(): bool
    {
        return $this->status === DataExportStatus::Ready && $this->path !== null;
    }

    /**
     * The archive's path on the export disk.
     *
     * The column is null for as long as the archive is being built, which is the
     * half of {@see isReady()} a caller cannot carry with it: the download route
     * checks readiness and then hands the path on, so this restates the same
     * 404 for a row that has none.
     */
    public function archivePath(): string
    {
        abort_if($this->path === null, 404);

        return $this->path;
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
            'status' => DataExportStatus::class,
            'size_bytes' => 'integer',
            'expires_at' => 'datetime',
        ];
    }
}
