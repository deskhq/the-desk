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
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Laravel\Scout\Searchable;

/**
 * @property string $id
 * @property string $channel_id
 * @property string $user_id
 * @property string $client_uuid
 * @property string|null $reply_to_id
 * @property string|null $thread_root_id
 * @property bool $sent_to_channel
 * @property int $reply_count
 * @property Carbon|null $last_reply_at
 * @property string $body
 * @property Carbon|null $edited_at
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Channel $channel
 * @property-read User $user
 * @property-read Message|null $replyTo
 * @property-read Collection<int, Message> $threadReplies
 * @property-read Collection<int, User> $threadParticipants
 * @property-read Collection<int, User> $mentionedUsers
 */
#[Fillable(['channel_id', 'user_id', 'client_uuid', 'reply_to_id', 'thread_root_id', 'sent_to_channel', 'body', 'edited_at'])]
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory, HasUuids, Searchable, SoftDeletes;

    /**
     * The model's default attribute values.
     *
     * Mirrors the database defaults so a freshly created message carries its
     * thread aggregates in memory (before any refresh) for the broadcast DTO.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'sent_to_channel' => false,
        'reply_count' => 0,
    ];

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
     * Get the replies posted into this message's thread.
     *
     * Only meaningful on a root message. Soft-deleted replies are excluded by
     * the default scope; callers that render tombstones opt in with withTrashed.
     *
     * @return HasMany<Message, $this>
     */
    public function threadReplies(): HasMany
    {
        return $this->hasMany(Message::class, 'thread_root_id');
    }

    /**
     * Get the distinct authors who have replied in this message's thread.
     *
     * Uses the `messages` table itself as the pivot (thread_root_id -> user_id),
     * so a root's participant avatars can be eager-loaded without an N+1.
     *
     * @return BelongsToMany<User, $this>
     */
    public function threadParticipants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'messages', 'thread_root_id', 'user_id')
            ->distinct();
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
            'last_reply_at' => 'datetime',
            'sent_to_channel' => 'bool',
            'reply_count' => 'int',
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
