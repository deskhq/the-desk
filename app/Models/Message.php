<?php

namespace App\Models;

use App\Data\MessageData;
use App\Enums\MessageType;
use App\Enums\NotificationLevel;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
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
 * @property string|null $forwarded_from_id
 * @property string|null $thread_root_id
 * @property bool $sent_to_channel
 * @property int $reply_count
 * @property Carbon|null $last_reply_at
 * @property string $body
 * @property MessageType $type
 * @property Carbon|null $edited_at
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Channel $channel
 * @property-read User $user
 * @property-read Message|null $replyTo
 * @property-read Message|null $forwardedFrom
 * @property-read Collection<int, Message> $threadReplies
 * @property-read Collection<int, User> $threadParticipants
 * @property-read Collection<int, User> $mentionedUsers
 * @property-read Collection<int, MessageReaction> $reactions
 */
#[Fillable(['channel_id', 'user_id', 'client_uuid', 'reply_to_id', 'forwarded_from_id', 'thread_root_id', 'sent_to_channel', 'body', 'type', 'edited_at'])]
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
        'type' => MessageType::Standard->value,
    ];

    /**
     * The relations {@see MessageData::fromMessage()} — and its nested reply,
     * forward, and reaction DTOs — read to render a message payload.
     *
     * This is the single home of the message payload's N+1 contract: every
     * timeline, thread, search, post, edit, and broadcast path eager-loads
     * exactly this set — query paths through {@see scopeWithMessageDataRelations()},
     * post-fetch paths through {@see loadMessageDataRelations()} or
     * {@see loadMessageDataRelationsInto()}. When `MessageData` starts reading a
     * new relation, add it here, never at a call site.
     *
     * @var list<string>
     */
    private const array MESSAGE_DATA_RELATIONS = [
        'user',
        'mentionedUsers',
        'linkPreviews',
        'reactions.user',
        'replyTo.user',
        'replyTo.mentionedUsers',
        'forwardedFrom.user',
        'forwardedFrom.channel',
        'forwardedFrom.mentionedUsers',
        'threadParticipants',
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
     * Get the source message this one forwards into its channel, if any.
     *
     * The source lives in another channel, so its `channel` relation carries the
     * attribution ("Forwarded from #name"). A soft-deleted source is still
     * resolved (withTrashed) so the client can render a "message deleted" stub in
     * the forwarded quote rather than dropping it.
     *
     * @return BelongsTo<Message, $this>
     */
    public function forwardedFrom(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'forwarded_from_id')->withTrashed();
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
     * Get the link previews extracted from this message's body.
     *
     * Kept in sync with the URLs in the body on every post and edit; each row
     * holds the unfurled Open Graph metadata (or a pending/failed status while
     * the queued job resolves it).
     *
     * @return HasMany<MessageLinkPreview, $this>
     */
    public function linkPreviews(): HasMany
    {
        return $this->hasMany(MessageLinkPreview::class)->orderBy('position');
    }

    /**
     * Get the emoji reactions added to this message.
     *
     * Ordered by when each reaction was first added so the aggregated pills keep
     * a stable, first-reacted-first order. Each row's `user` is eager-loaded when
     * building the reaction summary so the reactor tooltip avoids an N+1.
     *
     * @return HasMany<MessageReaction, $this>
     */
    public function reactions(): HasMany
    {
        return $this->hasMany(MessageReaction::class)->oldest()->orderBy('id');
    }

    /**
     * Correlated SQL (on the outer `messages.id` treated as a root) telling
     * whether a user follows the thread: they authored the root, replied in it,
     * or were @mentioned anywhere in it — the Slack-style auto-follow rule.
     * Binds the user id twice.
     */
    private const string THREAD_FOLLOWED_SQL = '(exists (
        select 1 from messages tm
        where (tm.id = messages.id or tm.thread_root_id = messages.id)
          and (
              tm.user_id = ?
              or exists (select 1 from mentions mn where mn.message_id = tm.id and mn.mentioned_user_id = ?)
          )
    ))';

    /**
     * Correlated SQL telling whether the thread holds an unread reply for a user:
     * a live reply by someone else after their `thread_reads` pointer (a null
     * pointer means the thread was never opened, so every reply counts). Suppressed
     * to false when the user has muted or quieted (level below "all") the root's
     * channel, mirroring the sidebar's unread-badge suppression so a cross-channel
     * query gets per-channel muting for free. Binds the user id three times, then
     * the "all" notification level.
     */
    private const string THREAD_HAS_UNREAD_SQL = '(exists (
        select 1 from messages r
        left join thread_reads tr on tr.thread_root_id = messages.id and tr.user_id = ?
        where r.thread_root_id = messages.id
          and r.deleted_at is null
          and r.user_id <> ?
          and (tr.last_read_reply_id is null or r.id > tr.last_read_reply_id)
    ) and not exists (
        select 1 from channel_members cm
        where cm.channel_id = messages.channel_id and cm.user_id = ?
          and (cm.muted = true or cm.notification_level <> ?)
    ))';

    /**
     * Annotate root messages with the viewer's per-thread follow and unread state
     * (`thread_followed` / `thread_has_unread`), which {@see MessageData}
     * reads. Correlated per outer row, so one query fills a page without an N+1.
     *
     * @param  Builder<Message>  $query
     */
    protected function scopeWithThreadReadState(Builder $query, User $user): void
    {
        $query->addSelect('messages.*')
            ->selectRaw(self::THREAD_FOLLOWED_SQL.'::int as thread_followed', [$user->id, $user->id])
            ->selectRaw(self::THREAD_HAS_UNREAD_SQL.'::int as thread_has_unread', [$user->id, $user->id, $user->id, NotificationLevel::All->value]);
    }

    /**
     * Constrain a root query to the threads a user follows (authored the root,
     * replied, or was @mentioned in the root or a reply). Reuses the same
     * {@see self::THREAD_FOLLOWED_SQL} the select annotation uses, so the inbox
     * filter and the `thread_followed` column can never disagree.
     *
     * @param  Builder<Message>  $query
     */
    protected function scopeFollowedBy(Builder $query, User $user): void
    {
        $query->whereRaw(self::THREAD_FOLLOWED_SQL, [$user->id, $user->id]);
    }

    /**
     * Constrain a root query to threads that hold an unread reply for the user,
     * with the same mute/level suppression as {@see self::THREAD_HAS_UNREAD_SQL}.
     *
     * @param  Builder<Message>  $query
     */
    protected function scopeWhereThreadUnreadFor(Builder $query, User $user): void
    {
        $query->whereRaw(self::THREAD_HAS_UNREAD_SQL, [$user->id, $user->id, $user->id, NotificationLevel::All->value]);
    }

    /**
     * Eager-load the message-payload relation set onto a message query, so the
     * resulting {@see MessageData} builds without an N+1. The one query
     * interface to {@see self::MESSAGE_DATA_RELATIONS}; callers add only the
     * extra relations their own read-model needs (e.g. the message's own
     * `channel` for search or the thread inbox) on top.
     *
     * @param  Builder<Message>  $query
     */
    protected function scopeWithMessageDataRelations(Builder $query): void
    {
        $query->with(self::MESSAGE_DATA_RELATIONS);
    }

    /**
     * Eager-load the message-payload relation set onto an already-fetched
     * message, for the post-create / post-edit broadcast paths that hold a model
     * rather than a query. Forces a reload so a freshly edited body's mentions
     * and previews are the current set. The single-model mirror of
     * {@see self::scopeWithMessageDataRelations()}.
     */
    public function loadMessageDataRelations(): static
    {
        $this->load(self::MESSAGE_DATA_RELATIONS);

        return $this;
    }

    /**
     * Eager-load the message-payload relation set across a hydrated collection
     * in a single batch, for the search path that holds its Scout matches as a
     * collection (not a query) and must not load per-row. The collection mirror
     * of {@see self::scopeWithMessageDataRelations()}.
     *
     * @param  Collection<int, Message>  $messages
     * @return Collection<int, Message>
     */
    public static function loadMessageDataRelationsInto(Collection $messages): Collection
    {
        return $messages->load(self::MESSAGE_DATA_RELATIONS);
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
            'edited_at' => 'datetime',
            'last_reply_at' => 'datetime',
            'sent_to_channel' => 'bool',
            'reply_count' => 'int',
            'type' => MessageType::class,
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
     * Keep soft-deleted messages and inert system notices out of the search index.
     */
    public function shouldBeSearchable(): bool
    {
        return ! $this->trashed() && ! $this->isSystem();
    }

    /**
     * Whether this row is an inert system notice (a member joined/left line)
     * rather than a user-authored message. Every message-interaction path guards
     * against it, and the unread / mention badges skip it.
     */
    public function isSystem(): bool
    {
        return $this->type->isSystem();
    }
}
