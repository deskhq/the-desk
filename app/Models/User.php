<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Concerns\HasTeams;
use App\Enums\ChimeSound;
use App\Enums\TeamRole;
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
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

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
 * @property bool $is_tombstone
 * @property array<int, string>|null $collapsed_channel_sections
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team|null $currentTeam
 * @property-read Collection<int, Team> $ownedTeams
 * @property-read Collection<int, Membership> $teamMemberships
 * @property-read Collection<int, Team> $teams
 * @property-read Collection<int, Channel> $channels
 * @property-read Collection<int, ChannelSection> $channelSections
 * @property-read Collection<int, DataExport> $dataExports
 */
#[Fillable(['name', 'email', 'pronouns', 'title', 'phone', 'timezone', 'password', 'current_team_id', 'chime_sound', 'share_read_receipts', 'collapsed_channel_sections', 'is_tombstone'])]
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
            'is_tombstone' => 'boolean',
            'collapsed_channel_sections' => 'array',
        ];
    }

    /**
     * Get the retained "Deleted User" tombstone account, creating it on first use.
     *
     * Authored messages are reassigned to this account when their real author
     * deletes their profile, so channel history reads coherently instead of
     * collapsing into gaps. It is never attached to a team and cannot be signed
     * into (its password is random and discarded).
     */
    public static function tombstone(): self
    {
        return static::firstOrCreate(
            ['is_tombstone' => true],
            [
                'name' => 'Deleted User',
                'email' => 'deleted-user@deleted.invalid',
                'password' => Hash::make(Str::random(40)),
            ],
        );
    }

    /**
     * Get the shared (non-personal) teams this user is the only owner of.
     *
     * Deleting the account would leave these teams ownerless, so the deletion
     * flow blocks until the user transfers ownership (see App\Http\Requests\
     * Settings\ProfileDeleteRequest).
     *
     * @return Collection<int, Team>
     */
    public function soleOwnedSharedTeams(): Collection
    {
        return $this->teams()
            ->where('is_personal', false)
            ->wherePivot('role', TeamRole::Owner->value)
            ->get()
            ->filter(fn (Team $team): bool => $team->members()
                ->wherePivot('role', TeamRole::Owner->value)
                ->where('users.id', '!=', $this->id)
                ->doesntExist())
            ->values();
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
     * The ids of the channels this user may see in a team — the whole
     * authorization boundary for message search, the thread inbox, unread
     * indicators, forwarding, and sidebar placement.
     *
     * "Visible" is membership scoped to the team: every channel the user belongs
     * to within it, archived ones included (they are still readable, just hidden
     * from the sidebar). This is the single home of that decision — no consumer
     * re-derives the ACL with its own query, so tightening it (a block-list, an
     * archived exclusion) is one change every consumer inherits.
     *
     * @return SupportCollection<int, string>
     */
    public function visibleChannelIds(Team $team): SupportCollection
    {
        return $this->channels()
            ->where('channels.team_id', $team->id)
            ->pluck('channels.id');
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

    /**
     * Get the user's recorded security activity, newest first.
     *
     * @return HasMany<SecurityEvent, $this>
     */
    public function securityEvents(): HasMany
    {
        return $this->hasMany(SecurityEvent::class)->latest();
    }

    /**
     * Get the user's requested data exports, newest first.
     *
     * @return HasMany<DataExport, $this>
     */
    public function dataExports(): HasMany
    {
        return $this->hasMany(DataExport::class)->latest();
    }
}
