<?php

namespace App\Models;

use App\Enums\MessageReminderStatus;
use Database\Factories\MessageReminderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $user_id
 * @property string $message_id
 * @property Carbon $remind_at
 * @property MessageReminderStatus $status
 * @property Carbon|null $fired_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Message $message
 */
#[Fillable(['user_id', 'message_id', 'remind_at', 'status', 'fired_at'])]
class MessageReminder extends Model
{
    /** @use HasFactory<MessageReminderFactory> */
    use HasFactory, HasUuids;

    /**
     * The model's default attribute values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => MessageReminderStatus::Pending->value,
    ];

    /**
     * Get the user who set the reminder.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the message the reminder points back to.
     *
     * Resolved withTrashed so a since-deleted message can still be recognised
     * when the nudge fires and rendered as a "message deleted" stub rather than
     * silently vanishing.
     *
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class)->withTrashed();
    }

    /**
     * Constrain the query to reminders still awaiting their due time.
     *
     * @param  Builder<MessageReminder>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', MessageReminderStatus::Pending);
    }

    /**
     * Constrain the query to pending reminders whose due time has arrived.
     *
     * @param  Builder<MessageReminder>  $query
     */
    public function scopeDue(Builder $query): void
    {
        $query->pending()->where('remind_at', '<=', now());
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'remind_at' => 'datetime',
            'fired_at' => 'datetime',
            'status' => MessageReminderStatus::class,
        ];
    }
}
