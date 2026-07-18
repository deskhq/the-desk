<?php

namespace App\Models;

use App\Concerns\HasTeams;
use App\Enums\AppLocale;
use App\Enums\ChimeSound;
use App\Enums\SidebarPosition;
use App\Enums\TeamRole;
use App\Enums\UserType;
use App\Observers\UserObserver;
use App\Support\Gravatar;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Passkeys\Passkey;

/**
 * @property string $id
 * @property string $name
 * @property string $email
 * @property UserType $type
 * @property string|null $avatar_url
 * @property string|null $avatar_path
 * @property string|null $pronouns
 * @property string|null $title
 * @property string|null $phone
 * @property string|null $timezone
 * @property AppLocale $locale
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property string|null $current_team_id
 * @property string|null $owner_team_id
 * @property ChimeSound $chime_sound
 * @property bool $share_read_receipts
 * @property SidebarPosition $sidebar_position
 * @property Carbon|null $onboarding_completed_at
 * @property bool $is_tombstone
 * @property Carbon|null $deactivated_at
 * @property array<int, string>|null $collapsed_channel_sections
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string|null $avatar
 * @property-read Team|null $currentTeam
 * @property-read Team|null $ownerTeam
 * @property-read Collection<int, Team> $ownedTeams
 * @property-read Collection<int, Membership> $teamMemberships
 * @property-read Collection<int, Team> $teams
 * @property-read Collection<int, Channel> $channels
 * @property-read Collection<int, ChannelSection> $channelSections
 * @property-read Collection<int, DataExport> $dataExports
 * @property-read Collection<int, Passkey> $passkeys
 */
#[Appends(['avatar'])]
#[Fillable(['name', 'email', 'avatar_url', 'pronouns', 'title', 'phone', 'timezone', 'locale', 'password', 'current_team_id', 'chime_sound', 'share_read_receipts', 'sidebar_position', 'onboarding_completed_at', 'collapsed_channel_sections', 'is_tombstone'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token', 'avatar_url', 'avatar_path'])]
#[ObservedBy(UserObserver::class)]
class User extends Authenticatable implements HasLocalePreference, MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasTeams, HasUuids, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Determine if the user has verified their email address.
     *
     * When the operator has email verification off (the default), treat every
     * account as verified: the `verified` middleware becomes a no-op and the
     * Registered listener won't send a confirmation email, so flipping the
     * EMAIL_VERIFICATION_ENABLED flag re-gates everyone instantly with no data
     * migration. When on, the real `email_verified_at` timestamp governs.
     */
    #[\Override]
    public function hasVerifiedEmail(): bool
    {
        if (! config('fortify.email_verification_enabled')) {
            return true;
        }

        return ! is_null($this->email_verified_at);
    }

    /**
     * The user's resolved avatar URL.
     *
     * The single source of truth for avatar resolution: every surface that
     * serialises a user (nav, team members, message authors, hover cards, read
     * receipts) reads this. An explicit `avatar_url` (an uploaded image, or the
     * demo seeder's generated identicons) wins; otherwise it derives from the
     * email's Gravatar. Null when there is no stored URL and Gravatar is
     * disabled, and — with the `404` default — a user without a Gravatar yields
     * a URL that 404s so the frontend cleanly falls back to its initials avatar.
     *
     * @return Attribute<covariant string|null, never>
     */
    protected function avatar(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->avatar_url ?? Gravatar::url($this->email));
    }

    /**
     * Get the recipient's preferred locale so mail and notifications render
     * in the language they chose in their settings.
     *
     * Falls back to the column's own default when the attribute isn't hydrated
     * — a freshly created (not-yet-refreshed) instance carries no `locale`, and
     * the send-on-register verification notification reads this before any
     * reload.
     */
    public function preferredLocale(): string
    {
        return ($this->locale ?? AppLocale::English)->value;
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
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'type' => UserType::class,
            'locale' => AppLocale::class,
            'chime_sound' => ChimeSound::class,
            'share_read_receipts' => 'boolean',
            'sidebar_position' => SidebarPosition::class,
            'onboarding_completed_at' => 'datetime',
            'is_tombstone' => 'boolean',
            'deactivated_at' => 'datetime',
            'collapsed_channel_sections' => 'array',
        ];
    }

    /**
     * The team a bot belongs to, or null for a human.
     *
     * A bot is team-scoped to exactly one team through this reference rather than
     * a team_members pivot row, which is what keeps it out of seat counts, member
     * lists, invites, DM pickers, and @mention autocomplete without any per-surface
     * filtering. Null once its team is deleted (see the migration's nullOnDelete).
     *
     * @return BelongsTo<Team, $this>
     */
    public function ownerTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'owner_team_id');
    }

    /**
     * Whether this account is a non-human integration identity (a bot).
     */
    public function isBot(): bool
    {
        return $this->type === UserType::Bot;
    }

    /**
     * Whether this account is a human.
     */
    public function isHuman(): bool
    {
        return $this->type === UserType::Human;
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
     * The ids of every channel this user may see across all their teams — the ACL
     * boundary for cross-team ("All workspaces") message search.
     *
     * The team-agnostic counterpart to {@see visibleChannelIds()}: membership
     * alone, unscoped by team. Because it is still the whole authorization
     * boundary, widening a search to this set can never surface a channel the user
     * is not a member of, in any team.
     *
     * @return SupportCollection<int, string>
     */
    public function visibleChannelIdsAcrossTeams(): SupportCollection
    {
        return $this->channels()->pluck('channels.id');
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

    /**
     * Get the user's linked external directory identities (OIDC, LDAP, SCIM).
     *
     * @return HasMany<SsoIdentity, $this>
     */
    public function ssoIdentities(): HasMany
    {
        return $this->hasMany(SsoIdentity::class);
    }

    /**
     * Whether the account has been deactivated (directory-pushed deprovisioning).
     *
     * A deactivated account is tombstoned rather than deleted: its history stays
     * intact but access is revoked (see App\Http\Middleware\EnsureUserIsActive and
     * App\Actions\Sso\SetSsoUserActivation). Reactivation clears `deactivated_at`.
     */
    public function isDeactivated(): bool
    {
        return $this->deactivated_at !== null;
    }
}
