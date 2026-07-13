<?php

namespace App\Models;

use App\Enums\ChannelType;
use App\Enums\ChannelVisibility;
use Database\Factories\ChannelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $team_id
 * @property string|null $name
 * @property string $slug
 * @property ChannelVisibility $visibility
 * @property ChannelType $type
 * @property string|null $dm_key
 * @property string|null $topic
 * @property string|null $created_by
 * @property Carbon|null $archived_at
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
#[Fillable(['team_id', 'name', 'slug', 'visibility', 'type', 'dm_key', 'topic', 'created_by', 'archived_at'])]
class Channel extends Model
{
    /** @use HasFactory<ChannelFactory> */
    use HasFactory, HasUuids;

    /**
     * The reserved slug of the auto-created, undeletable channel.
     */
    public const string GENERAL_SLUG = 'general';

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
            ->withPivot(['last_read_message_id', 'muted', 'notification_level', 'draft'])
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
            'type' => ChannelType::class,
            'archived_at' => 'datetime',
        ];
    }
}
