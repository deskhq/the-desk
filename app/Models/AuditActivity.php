<?php

namespace App\Models;

use App\Exceptions\AuditLogImmutableException;
use Database\Factories\AuditActivityFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Activity;

/**
 * An append-only record of an admin or moderation action within a workspace.
 *
 * Extends Spatie's activity model to carry the app's UUID keys and a `team_id`
 * for workspace scoping. Immutability is enforced here at the model layer:
 * once created, an entry can never be updated or deleted.
 *
 * @property string $id
 * @property string $team_id
 * @property-read Team $team
 */
class AuditActivity extends Activity
{
    /** @use HasFactory<AuditActivityFactory> */
    use HasFactory, HasUuids;

    /**
     * Guard against any mutation after creation so the log stays append-only.
     */
    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new AuditLogImmutableException('Audit log entries cannot be updated.');
        });

        static::deleting(function (): never {
            throw new AuditLogImmutableException('Audit log entries cannot be deleted.');
        });
    }

    /**
     * Get the workspace the audit entry belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
