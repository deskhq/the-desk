<?php

namespace App\Models;

use App\Data\MessageData;
use App\Enums\MessageType;
use App\Enums\NotificationLevel;
use App\Support\MessagePlainText;
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
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Laravel\Scout\Searchable;

/**
 * @property string $id
 * @property string $channel_id
 * @property string $user_id
 * @property string|null $incoming_webhook_id
 * @property string $client_uuid
 * @property string|null $reply_to_id
 * @property string|null $forwarded_from_id
 * @property string|null $thread_root_id
 * @property bool $sent_to_channel
 * @property int $reply_count
 * @property Carbon|null $last_reply_at
 * @property string $body
 * @property string|null $author_override_name
 * @property string|null $author_override_avatar_url
 * @property MessageType $type
 * @property Carbon|null $edited_at
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Channel $channel
 * @property-read User $user
 * @property-read IncomingWebhook|null $incomingWebhook
 * @property-read PersonalAccessToken|null $token
 * @property-read Message|null $replyTo
 * @property-read Message|null $forwardedFrom
 * @property-read Collection<int, Message> $threadReplies
 * @property-read Collection<int, User> $threadParticipants
 * @property-read Collection<int, User> $mentionedUsers
 * @property-read Collection<int, MessageReaction> $reactions
 * @property-read Poll|null $poll
 * @property-read MessagePin|null $pin
 * @property-read Collection<int, Attachment> $attachments
 */
#[Fillable(['channel_id', 'user_id', 'incoming_webhook_id', 'token_id', 'client_uuid', 'reply_to_id', 'forwarded_from_id', 'thread_root_id', 'sent_to_channel', 'body', 'author_override_name', 'author_override_avatar_url', 'type', 'edited_at'])]
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
        // Read only for a viewer who can manage the team's integrations, but
        // loaded for everyone: a conditional eager-load here would be a lazy
        // load — or an N+1 — the day a caller forgets the condition.
        'incomingWebhook',
        // Its REST API counterpart, loaded on the same terms — and with its
        // `tokenable`, since naming the token is only offered for a bot's own
        // credential (a human's personal access token stays unnamed).
        'token.tokenable',
        'mentionedUsers',
        'linkPreviews',
        'reactions.user',
        // A poll message's votable payload: its options in author order, each with
        // the votes (and voters) that build the tally and roster.
        'poll.options.votes.user',
        'pin.pinnedBy',
        'replyTo.user',
        'replyTo.mentionedUsers',
        'forwardedFrom.user',
        'forwardedFrom.channel',
        'forwardedFrom.mentionedUsers',
        'threadParticipants',
        // The channel + team are pulled through so each attachment can build its
        // authorized download URL without an N+1 (see Attachment::url()).
        'attachments.channel.team',
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
     * Get the incoming webhook that produced this message, if any.
     *
     * Null on every path but webhook ingest, and null on a webhook message that
     * predates the column. Null too once the webhook row is deleted outright —
     * the message survives its credential, which is the point.
     *
     * @return BelongsTo<IncomingWebhook, $this>
     */
    public function incomingWebhook(): BelongsTo
    {
        return $this->belongsTo(IncomingWebhook::class);
    }

    /**
     * Get the API token that produced this message, if any.
     *
     * Null on every path but a REST API v1 post. Null too once the token is
     * revoked or deleted — the message outlives its credential by design, so the
     * attribution thins rather than the history disappearing.
     *
     * @return BelongsTo<PersonalAccessToken, $this>
     */
    public function token(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class);
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
     * Get this message's poll, if it is a poll message.
     *
     * At most one poll exists per message (enforced by the `polls` table's unique
     * `message_id`), so this is a HasOne. Its options and votes are eager-loaded
     * with the message payload so the tally and roster build without an N+1.
     *
     * @return HasOne<Poll, $this>
     */
    public function poll(): HasOne
    {
        return $this->hasOne(Poll::class);
    }

    /**
     * Get this message's pin to its channel, if any.
     *
     * At most one pin exists per message (enforced by the table's unique
     * constraint), so this is a HasOne. The `pinnedBy` user is eager-loaded with
     * the message payload so the "Pinned by :name" attribution avoids an N+1.
     *
     * @return HasOne<MessagePin, $this>
     */
    public function pin(): HasOne
    {
        return $this->hasOne(MessagePin::class);
    }

    /**
     * Get the files attached to this message, in upload order.
     *
     * Only claimed (attached) rows belong to a message; pending uploads carry no
     * `message_id`. Ordered by creation so the client renders a stable gallery.
     *
     * @return HasMany<Attachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class)->oldest()->orderBy('id');
    }

    /**
     * The rule that decides whether a message is ordinary channel traffic: a
     * top-level message, or a thread reply its author explicitly also sent to
     * the channel. A thread-only reply is not — it lives in the thread view, so
     * it stays out of the main timeline and out of the plain unread badge, and
     * only ever alerts through the mention path.
     *
     * Qualified against `messages` because the sidebar correlates this against a
     * query that has `channels` and `channel_members` joined in.
     *
     * One constant, so the scope below, the conditional aggregate in
     * `WorkspaceUnread` and the timeline's filter cannot disagree about what
     * "channel traffic" means. Its client twin is
     * `resources/js/lib/channelTraffic.ts`; ADR-0010 records why both exist and
     * why neither may be re-inlined.
     */
    private const string CHANNEL_TRAFFIC_SQL = '(messages.thread_root_id is null or messages.sent_to_channel = true)';

    /**
     * Constrain a message query to ordinary channel traffic, per
     * {@see self::CHANNEL_TRAFFIC_SQL}.
     *
     * Deliberately opt-in per call site rather than a global scope: a mention
     * anywhere — including inside a thread — still badges its channel, so the
     * sidebar's mention count is one of the queries that must *not* carry it.
     *
     * @param  Builder<Message>  $query
     */
    protected function scopeChannelTraffic(Builder $query): void
    {
        $query->whereRaw(self::CHANNEL_TRAFFIC_SQL);
    }

    /**
     * The same rule as a SQL fragment, for the one caller that needs it inside
     * an expression rather than as a `where`: `WorkspaceUnread` counts channel
     * traffic and mentions in a single grouped query, so its unread half is a
     * conditional aggregate that no scope can express.
     *
     * Reach for {@see self::scopeChannelTraffic()} everywhere else — a `where`
     * that goes through this instead is a `whereRaw` with extra steps.
     *
     * Typed `literal-string` because that is what the query builder's raw
     * methods take: it is what makes "this fragment can hold no user input" a
     * static guarantee rather than a promise in a comment.
     *
     * @return literal-string
     */
    public static function channelTrafficSql(): string
    {
        return self::CHANNEL_TRAFFIC_SQL;
    }

    /**
     * The in-memory twin of {@see self::CHANNEL_TRAFFIC_SQL}, for the paths that
     * hold a payload rather than a query — the push listener decides from the
     * broadcast {@see MessageData}, and re-reading the row to ask the
     * database would be a query per message per send.
     *
     * Kept beside the SQL, and pinned against the same case table
     * (`tests/Fixtures/channel-traffic-cases.json`) as both the scope and the
     * client twin, so the three cannot drift.
     */
    public static function isChannelTraffic(?string $threadRootId, bool $sentToChannel): bool
    {
        return $threadRootId === null || $sentToChannel;
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
     * How many times {@see self::threadUnreadRepliesSql()} binds the user id, so
     * the three call sites below say "the tail's bindings" rather than each
     * counting the question marks by eye.
     */
    private const int THREAD_UNREAD_REPLIES_BINDINGS = 4;

    /**
     * The correlated tail of an unread-reply lookup, from the `from` keyword on:
     * a live reply by someone else after the user's `thread_reads` pointer (a null
     * pointer means the thread was never opened, so every reply counts) that the
     * viewer's preference for the root's channel lets through.
     *
     * That last clause is the alert rule, read from its one home
     * ({@see NotificationLevel}) rather than re-spelled here: a reply @mentioning
     * the viewer alerts unless the channel is muted or set to "nothing", any
     * other reply only at "all". Applying it per reply rather than per channel is
     * what makes a mention inside a thread on a "mentions"-level channel raise
     * the dot the channel badge and the chime already raise for it (#1143). A
     * viewer with no membership row has no preference to be silenced by, which
     * the outer join's null side carries.
     *
     * Shared by the `exists` flag and the count below, so the dot and the
     * ":count new replies" line can never disagree. Binds the user id
     * {@see self::THREAD_UNREAD_REPLIES_BINDINGS} times.
     *
     * @return literal-string
     */
    private function threadUnreadRepliesSql(): string
    {
        return 'from messages r
        left join thread_reads tr on tr.thread_root_id = messages.id and tr.user_id = ?
        left join channel_members cm on cm.channel_id = messages.channel_id and cm.user_id = ?
        where r.thread_root_id = messages.id
          and r.deleted_at is null
          and r.user_id <> ?
          and (tr.last_read_reply_id is null or r.id > tr.last_read_reply_id)
          and (
              cm.user_id is null
              or '.NotificationLevel::alertsOnUnreadSql('cm').'
              or ('.NotificationLevel::alertsOnMentionSql('cm').' and exists (
                  select 1 from mentions mn where mn.message_id = r.id and mn.mentioned_user_id = ?
              ))
          )';
    }

    /**
     * Correlated SQL telling whether the thread holds an unread reply that alerts
     * the user. Binds the user id {@see self::THREAD_UNREAD_REPLIES_BINDINGS}
     * times.
     *
     * @return literal-string
     */
    private function threadHasUnreadSql(): string
    {
        return '(exists (select 1 '.$this->threadUnreadRepliesSql().'))';
    }

    /**
     * Correlated SQL counting the thread's replies that are new to a user — what
     * the inbox renders as ":count new replies", never derived from the total.
     * Because the silencing lives inside the shared tail, a channel that alerts
     * on nothing counts to zero without a case expression, and the dot is exactly
     * "this count is not zero". Binds the user id
     * {@see self::THREAD_UNREAD_REPLIES_BINDINGS} times.
     *
     * @return literal-string
     */
    private function threadUnreadReplyCountSql(): string
    {
        return '(select count(*) '.$this->threadUnreadRepliesSql().')';
    }

    /**
     * Annotate root messages with the viewer's per-thread follow and unread state
     * (`thread_followed` / `thread_has_unread` / `thread_unread_reply_count`),
     * which {@see MessageData} reads. Correlated per outer row, so one query fills
     * a page without an N+1.
     *
     * @param  Builder<Message>  $query
     */
    protected function scopeWithThreadReadState(Builder $query, User $user): void
    {
        $query->addSelect('messages.*')
            ->selectRaw(self::THREAD_FOLLOWED_SQL.'::int as thread_followed', [$user->id, $user->id])
            ->selectRaw($this->threadHasUnreadSql().'::int as thread_has_unread', $this->threadUnreadBindings($user))
            ->selectRaw($this->threadUnreadReplyCountSql().'::int as thread_unread_reply_count', $this->threadUnreadBindings($user));
    }

    /**
     * The bindings {@see self::threadUnreadRepliesSql()} takes: the viewer's id,
     * once per correlated lookup inside it.
     *
     * @return array<int, string>
     */
    private function threadUnreadBindings(User $user): array
    {
        return array_fill(0, self::THREAD_UNREAD_REPLIES_BINDINGS, $user->id);
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
     * Constrain a root query to threads that hold an unread reply for the user.
     * Reuses {@see self::threadHasUnreadSql()}, so the inbox's "Unread" filter
     * and the `thread_has_unread` column can never disagree.
     *
     * @param  Builder<Message>  $query
     */
    protected function scopeWhereThreadUnreadFor(Builder $query, User $user): void
    {
        $query->whereRaw($this->threadHasUnreadSql(), $this->threadUnreadBindings($user));
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
     * A poll message has an empty body, so its question is folded into the indexed
     * text (option labels are not) — the question is what a search should match,
     * and the client highlights it through the same `body` snippet.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            // Index the readable text (mentions unwrapped to their names) so a
            // search matches — and Meilisearch highlights — the name, not the raw
            // `@[Name](id)` token. A poll folds its question in here (its body is
            // empty), so a poll is found by its question, not its option labels.
            'body' => trim(MessagePlainText::from($this->body).' '.($this->type === MessageType::Poll ? $this->poll->question : '')),
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
