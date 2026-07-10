<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Concerns\HasTeams;
use App\Enums\ChimeSound;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string|null $pronouns
 * @property string|null $title
 * @property string|null $phone
 * @property string|null $timezone
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property string|null $current_team_id
 * @property ChimeSound $chime_sound
 * @property bool $share_read_receipts
 * @property array<int, string>|null $collapsed_channel_sections
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team|null $currentTeam
 * @property-read Collection<int, Team> $ownedTeams
 * @property-read Collection<int, Membership> $teamMemberships
 * @property-read Collection<int, Team> $teams
 * @property-read Collection<int, Channel> $channels
 * @property-read Collection<int, ChannelSection> $channelSections
 */
#[Fillable(['name', 'email', 'pronouns', 'title', 'phone', 'timezone', 'password', 'current_team_id', 'chime_sound', 'share_read_receipts', 'collapsed_channel_sections'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasTeams, HasUuids, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'chime_sound' => ChimeSound::class,
            'share_read_receipts' => 'boolean',
            'collapsed_channel_sections' => 'array',
        ];
    }

    /**
     * Get all of the channels the user is a member of.
     *
     * @return BelongsToMany<Channel, $this>
     */
    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(Channel::class, 'channel_members')
            ->withPivot(['last_read_message_id', 'muted', 'notification_level', 'draft', 'starred', 'section_id', 'position'])
            ->withTimestamps();
    }

    /**
     * Get the user's custom sidebar sections across all their teams.
     *
     * @return HasMany<ChannelSection, $this>
     */
    public function channelSections(): HasMany
    {
        return $this->hasMany(ChannelSection::class);
    }
}
