<?php

namespace App\Models;

use Database\Factories\ChannelSectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $user_id
 * @property string $team_id
 * @property string $name
 * @property int $position
 * @property bool $collapsed
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Team $team
 * @property-read Collection<int, ChannelMember> $channelMembers
 */
#[Fillable(['user_id', 'team_id', 'name', 'position', 'collapsed'])]
class ChannelSection extends Model
{
    /** @use HasFactory<ChannelSectionFactory> */
    use HasFactory, HasUuids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'collapsed' => 'boolean',
        ];
    }

    /**
     * Get the user who owns the section.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the team the section belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the channel memberships filed under this section.
     *
     * @return HasMany<ChannelMember, $this>
     */
    public function channelMembers(): HasMany
    {
        return $this->hasMany(ChannelMember::class, 'section_id');
    }
}
