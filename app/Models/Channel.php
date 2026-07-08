<?php

namespace App\Models;

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
 * @property string $name
 * @property string $slug
 * @property ChannelVisibility $visibility
 * @property string|null $topic
 * @property string|null $created_by
 * @property Carbon|null $archived_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read User $creator
 * @property-read Collection<int, ChannelMember> $channelMembers
 * @property-read Collection<int, User> $members
 */
#[Fillable(['team_id', 'name', 'slug', 'visibility', 'topic', 'created_by', 'archived_at'])]
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
     * Get the users who are members of the channel.
     *
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'channel_members')
            ->withPivot(['last_read_message_id'])
            ->withTimestamps();
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'visibility' => ChannelVisibility::class,
            'archived_at' => 'datetime',
        ];
    }
}
