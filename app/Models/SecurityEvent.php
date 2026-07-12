<?php

namespace App\Models;

use App\Enums\SecurityEventType;
use Database\Factories\SecurityEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A record of a security-relevant account action (sign in/out, credential and
 * two-factor changes), captured with the request's IP and User-Agent so users
 * can review recent activity and spot unfamiliar access.
 *
 * @property string $id
 * @property string $user_id
 * @property SecurityEventType $type
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property bool $is_new_device
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'type', 'ip_address', 'user_agent', 'is_new_device'])]
class SecurityEvent extends Model
{
    /** @use HasFactory<SecurityEventFactory> */
    use HasFactory, HasUuids;

    /**
     * Get the user the security event belongs to.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
            'type' => SecurityEventType::class,
            'is_new_device' => 'boolean',
        ];
    }
}
