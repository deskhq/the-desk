<?php

namespace App\Models;

use App\Enums\ChannelType;
use App\Enums\ChannelVisibility;
use App\Support\NameSlug;
use Database\Factories\ChannelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $team_id
 * @property string|null $name
 * @property string $slug
 * @property ChannelVisibility $visibility
 * @property bool $is_default
 * @property ChannelType $type
 * @property string|null $dm_key
 * @property string|null $topic
 * @property string|null $description
 * @property string|null $created_by
 * @property Carbon|null $archived_at
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read int|null $unread_count
 * @property-read int|null $mention_count
 * @property-read bool|null $muted
 * @property-read string|null $notification_level
 * @property-read Team $team
 * @property-read User $creator
 * @property-read Collection<int, ChannelMember> $channelMembers
 * @property-read Collection<int, User> $members
 * @property-read Collection<int, Message> $messages
 */
#[Fillable(['team_id', 'name', 'slug', 'visibility', 'is_default', 'type', 'dm_key', 'topic', 'description', 'created_by', 'archived_at'])]
class Channel extends Model
{
    /**
     * Soft deletes are the grace window an admin's "Delete channel" opens: the
     * stamp hides the channel from every read path at once — the relation-backed
     * sidebar, browse, the {@see User::visibleChannelIds()} ACL search and the
     * thread inbox share, and route-model binding, which all inherit the trait's
     * global scope — while the scheduled purge does the irreversible work only
     * once the window has closed.
     *
     * @use HasFactory<ChannelFactory>
     */
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * The reserved slug of the auto-created, undeletable channel.
     */
    public const string GENERAL_SLUG = 'general';

    /**
     * Slug base used when a channel name carries no sluggable characters.
     */
    public const string FALLBACK_SLUG = 'channel';

    /**
     * How long a deleted channel stays restorable before it is purged.
     *
     * A rule of the channel, not of the job that enforces it: the delete dialog
     * promises the window, the restore path relies on it, and the scheduled
     * purge is only the sweeper that closes it. A fixed window for now — once
     * workspace-level retention lands this becomes the default rather than the
     * only answer (issue #401).
     */
    public const int RESTORE_WINDOW_DAYS = 30;

    /**
     * Keep a usable slug on the row however the channel is written.
     *
     * The slug is the route key ({@see getRouteKeyName()}), so a blank one
     * leaves the channel unreachable with no UI path back to it (issue #924).
     * The create path derives the slug itself; this is the backstop for every
     * other writer, including a slug blanked on an existing row.
     */
    #[\Override]
    protected static function boot(): void
    {
        parent::boot();

        static::saving(function (Channel $channel): void {
            if (blank($channel->slug)) {
                $channel->slug = NameSlug::distinct((string) $channel->name, self::FALLBACK_SLUG);
            }
        });
    }

    /**
     * Query the channels every new member of the given workspace is joined to.
     *
     * The protected #general is a default in code rather than by flag, so it is
     * matched by slug alongside whatever admins have marked. Archived and
     * deleted channels are excluded — the trait's global scope drops the latter
     * — so a default that has since been retired quietly stops applying instead
     * of dropping newcomers into a read-only room.
     *
     * Keyed on the workspace's id rather than the model, because the one caller
     * is the membership observer: a membership can be written for a workspace
     * the soft-delete scope would hide, and its arrival still has to place the
     * member in #general the way it always has.
     *
     * @return Builder<Channel>
     */
    public static function defaultsForTeam(string $teamId): Builder
    {
        return self::query()
            ->where('team_id', $teamId)
            ->where('visibility', ChannelVisibility::Public->value)
            ->whereNull('archived_at')
            ->where(fn (Builder $query): Builder => $query
                ->where('is_default', true)
                ->orWhere('slug', self::GENERAL_SLUG));
    }

    /**
     * Determine whether this is the team's protected #general channel.
     */
    public function isGeneral(): bool
    {
        return $this->slug === self::GENERAL_SLUG;
    }

    /**
     * Determine whether the channel is a 1:1 direct message.
     */
    public function isDirect(): bool
    {
        return $this->type === ChannelType::Direct;
    }

    /**
     * Determine whether the channel is a group direct message (3+ participants).
     */
    public function isGroupDirect(): bool
    {
        return $this->type === ChannelType::GroupDirect;
    }

    /**
     * Determine whether the channel is any kind of direct message — a 1:1 or a
     * group conversation. This is the DM-vs-standard distinction the sidebar
     * grouping, mention scoping, and the hide / archive / leave rules key on;
     * {@see isDirect()} narrows that to the 1:1 case where a single "other
     * participant" is meaningful.
     */
    public function isDirectMessage(): bool
    {
        if ($this->isDirect()) {
            return true;
        }

        return $this->isGroupDirect();
    }

    /**
     * Resolve the DM participant to display to the given viewer.
     *
     * DMs render viewer-relative: in a two-person DM the viewer sees the other
     * participant; in a self-DM (a single member) they see themselves, which the
     * frontend labels "You". Returns null for a standard channel.
     */
    public function directParticipantFor(User $viewer): ?User
    {
        if (! $this->isDirect()) {
            return null;
        }

        return $this->members()->where('users.id', '!=', $viewer->id)->first()
            ?? $this->members()->whereKey($viewer->id)->first();
    }

    /**
     * The channel name as the given viewer sees it.
     *
     * Standard channels keep their stored name. DMs store `name = null` and
     * render viewer-relative: the other participants' names, sorted and
     * comma-joined (the lone counterpart in a 1:1); a self-DM, whose only
     * member is the viewer, shows the viewer's own name.
     */
    public function displayNameFor(User $viewer): string
    {
        if (! $this->isDirectMessage()) {
            return (string) $this->name;
        }

        $counterpartNames = $this->members
            ->reject(fn (User $member): bool => $member->id === $viewer->id)
            ->sortBy('name')
            ->pluck('name');

        return $counterpartNames->isEmpty() ? $viewer->name : $counterpartNames->join(', ');
    }

    /**
     * Determine whether the channel is archived.
     */
    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Get the team the channel belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the user who created the channel.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the channel membership records.
     *
     * @return HasMany<ChannelMember, $this>
     */
    public function channelMembers(): HasMany
    {
        return $this->hasMany(ChannelMember::class);
    }

    /**
     * Get the messages posted to the channel.
     *
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * Get the polls posted to the channel, through their messages.
     *
     * Backs the scope-bound poll routes (vote / close): a poll has no direct
     * `channel_id`, so this HasManyThrough lets route-model binding resolve a
     * `{poll}` nested under `{channel}` to a poll that genuinely belongs to it.
     *
     * @return HasManyThrough<Poll, Message, $this>
     */
    public function polls(): HasManyThrough
    {
        return $this->hasManyThrough(Poll::class, Message::class);
    }

    /**
     * Get the file attachments uploaded to the channel (both pending uploads and
     * those claimed by a message). Denormalized onto `channel_id` so the serve
     * route can scope-bind an attachment to its channel without joining through
     * `messages`.
     *
     * @return HasMany<Attachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    /**
     * Get the messages scheduled for future delivery to the channel.
     *
     * @return HasMany<ScheduledMessage, $this>
     */
    public function scheduledMessages(): HasMany
    {
        return $this->hasMany(ScheduledMessage::class);
    }

    /**
     * Get the channel's pinned-message rows, most-recently-pinned first.
     *
     * Denormalized onto `channel_id`, so the pin count and the pins panel query
     * never join through `messages`. Ordered by when each pin was created so the
     * panel lists the freshest pins on top.
     *
     * @return HasMany<MessagePin, $this>
     */
    public function pins(): HasMany
    {
        return $this->hasMany(MessagePin::class)->latest()->orderBy('id');
    }

    /**
     * Get the users who are members of the channel.
     *
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'channel_members')
            ->withPivot(ChannelMember::PIVOT_COLUMNS)
            ->withTimestamps();
    }

    /**
     * Get the route key for the model.
     */
    #[\Override]
    public function getRouteKeyName(): string
    {
        return 'slug';
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
            'visibility' => ChannelVisibility::class,
            'is_default' => 'boolean',
            'type' => ChannelType::class,
            'archived_at' => 'datetime',
        ];
    }
}
