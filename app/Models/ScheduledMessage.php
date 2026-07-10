<?php

namespace App\Models;

use App\Enums\ScheduledMessageStatus;
use Database\Factories\ScheduledMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $channel_id
 * @property string $user_id
 * @property string $client_uuid
 * @property string $body
 * @property string|null $reply_to_id
 * @property Carbon $send_at
 * @property ScheduledMessageStatus $status
 * @property Carbon|null $sent_at
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $failed_at
 * @property string|null $failure_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Channel $channel
 * @property-read User $user
 * @property-read Message|null $replyTo
 */
#[Fillable(['channel_id', 'user_id', 'client_uuid', 'body', 'reply_to_id', 'send_at', 'status', 'sent_at', 'cancelled_at', 'failed_at', 'failure_reason'])]
class ScheduledMessage extends Model
{
    /** @use HasFactory<ScheduledMessageFactory> */
    use HasFactory, HasUuids;

    /**
     * The model's default attribute values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => ScheduledMessageStatus::Pending->value,
    ];

    /**
     * Get the channel the message is scheduled for.
     *
     * @return BelongsTo<Channel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * Get the user who scheduled the message.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the message this one will quote inline once delivered, if any.
     *
     * Resolved withTrashed so a since-deleted target can be recognised at
     * delivery and its quote dropped rather than blocking the send.
     *
     * @return BelongsTo<Message, $this>
     */
    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'reply_to_id')->withTrashed();
    }

    /**
     * Constrain the query to rows still awaiting delivery.
     *
     * @param  Builder<ScheduledMessage>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', ScheduledMessageStatus::Pending);
    }

    /**
     * Constrain the query to pending rows whose send time has arrived.
     *
     * @param  Builder<ScheduledMessage>  $query
     */
    public function scopeDue(Builder $query): void
    {
        $query->pending()->where('send_at', '<=', now());
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'send_at' => 'datetime',
            'sent_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'failed_at' => 'datetime',
            'status' => ScheduledMessageStatus::class,
        ];
    }
}
