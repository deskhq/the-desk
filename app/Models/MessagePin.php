<?php

namespace App\Models;

use Database\Factories\MessagePinFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $message_id
 * @property string $channel_id
 * @property string $pinned_by
 * @property Carbon|null $created_at
 * @property-read Message $message
 * @property-read Channel $channel
 * @property-read User $pinnedBy
 */
#[Fillable(['message_id', 'channel_id', 'pinned_by'])]
class MessagePin extends Model
{
    /** @use HasFactory<MessagePinFactory> */
    use HasFactory, HasUuids;

    /**
     * A pin is created and destroyed but never edited, so it tracks only its
     * `created_at`; disabling `updated_at` keeps the missing column from breaking
     * inserts.
     */
    public const ?string UPDATED_AT = null;

    /**
     * Get the message that was pinned.
     *
     * Resolved with soft-deleted rows included so a pin can still be read while a
     * message tombstone lingers; in practice a soft delete removes the pin, so
     * this only backstops a race.
     *
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class)->withTrashed();
    }

    /**
     * Get the channel the message was pinned to.
     *
     * @return BelongsTo<Channel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * Get the user who pinned the message.
     *
     * @return BelongsTo<User, $this>
     */
    public function pinnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pinned_by');
    }
}
