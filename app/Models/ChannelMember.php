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
#[Fillable(['channel_id', 'user_id', ...ChannelMember::PIVOT_COLUMNS])]
class ChannelMember extends Model
{
    /**
     * The membership's own state: every column that is neither the row's
     * identity (`id`, `channel_id`, `user_id`) nor its timestamps.
     *
     * This is the pivot's column set, and the only declaration of it. The two
     * `withPivot()` calls that expose the row through a `BelongsToMany`
     * ({@see User::channels()}, {@see Channel::members()}), the mass-assignment
     * list above, and `SidebarChannels`' select all read it, so a column added
     * here reaches every reader at once — which `hidden_at` did not, for three
     * of the four, until it did.
     *
     * @var list<string>
     */
    public const array PIVOT_COLUMNS = [
        'last_read_message_id',
        'muted',
        'notification_level',
        'draft',
        'starred',
        'section_id',
        'position',
        'hidden_at',
    ];

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
