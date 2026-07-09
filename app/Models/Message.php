<?php

namespace App\Models;

use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Laravel\Scout\Searchable;

/**
 * @property string $id
 * @property string $channel_id
 * @property string $user_id
 * @property string $client_uuid
 * @property string|null $reply_to_id
 * @property string $body
 * @property Carbon|null $edited_at
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Channel $channel
 * @property-read User $user
 * @property-read Message|null $replyTo
 * @property-read Collection<int, User> $mentionedUsers
 */
#[Fillable(['channel_id', 'user_id', 'client_uuid', 'reply_to_id', 'body', 'edited_at'])]
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory, HasUuids, Searchable, SoftDeletes;

    /**
     * Get the channel the message was posted to.
     *
     * @return BelongsTo<Channel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * Get the user who authored the message.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the parent message this one quotes inline, if any.
     *
     * A soft-deleted parent is still resolved (withTrashed) so the client can
     * render a "message deleted" stub in the quote rather than dropping it.
     *
     * @return BelongsTo<Message, $this>
     */
    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'reply_to_id')->withTrashed();
    }

    /**
     * Get the team members mentioned in this message.
     *
     * Backed by the `mentions` join table; the parser keeps these rows in sync
     * with the `@[Name](user-id)` tokens in the body on every post and edit.
     *
     * @return BelongsToMany<User, $this>
     */
    public function mentionedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'mentions', 'message_id', 'mentioned_user_id')
            ->withTimestamps();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'edited_at' => 'datetime',
        ];
    }

    /**
     * Get the indexed representation of the message.
     *
     * `team_id` is derived from the channel because messages carry no native
     * team column; the channel relation is eager-loaded when indexing in bulk.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,
            'channel_id' => $this->channel_id,
            'user_id' => $this->user_id,
            'team_id' => $this->channel->team_id,
            'created_at' => $this->created_at?->getTimestamp(),
        ];
    }

    /**
     * Keep soft-deleted messages out of the search index.
     */
    public function shouldBeSearchable(): bool
    {
        return ! $this->trashed();
    }
}
