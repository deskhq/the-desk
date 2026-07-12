<?php

namespace App\Models;

use App\Enums\NotificationLevel;
use Database\Factories\ChannelMemberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $channel_id
 * @property string $user_id
 * @property string|null $last_read_message_id
 * @property bool $muted
 * @property NotificationLevel $notification_level
 * @property string|null $draft
 * @property bool $starred
 * @property string|null $section_id
 * @property int $position
 * @property Carbon|null $hidden_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Channel $channel
 * @property-read User $user
 * @property-read ChannelSection|null $section
 */
#[Fillable(['channel_id', 'user_id', 'last_read_message_id', 'muted', 'notification_level', 'draft', 'starred', 'section_id', 'position'])]
class ChannelMember extends Model
{
    /** @use HasFactory<ChannelMemberFactory> */
    use HasFactory, HasUuids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'muted' => 'boolean',
            'notification_level' => NotificationLevel::class,
            'starred' => 'boolean',
            'position' => 'integer',
            'hidden_at' => 'datetime',
        ];
    }

    /**
     * Get the channel the membership belongs to.
     *
     * @return BelongsTo<Channel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * Get the custom section the membership is filed under, if any.
     *
     * @return BelongsTo<ChannelSection, $this>
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(ChannelSection::class, 'section_id');
    }

    /**
     * Get the user the membership belongs to.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
