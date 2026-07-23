<?php

namespace App\Models;

use App\Actions\Users\ClearExpiredUserStatuses;
use App\Concerns\HasTeams;
use App\Data\UserData;
use App\Data\UserDndData;
use App\Data\UserStatusData;
use App\Enums\AppLocale;
use App\Enums\ChimeSound;
use App\Enums\PresenceState;
use App\Enums\SidebarPosition;
use App\Enums\TeamRole;
use App\Enums\TimeFormat;
use App\Enums\UserType;
use App\Observers\UserObserver;
use App\Support\Gravatar;
use App\Support\Images\ImageProxy;
use App\Support\PresenceRegistry;
use Carbon\CarbonInterface;
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
use Illuminate\Database\Eloquent\Relations\MorphMany;
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
use Laravel\Sanctum\HasApiTokens;

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
 * @property string|null $status_emoji
 * @property string|null $status_text
 * @property Carbon|null $status_expires_at
 * @property string|null $timezone
 * @property AppLocale $locale
 * @property TimeFormat $time_format
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
 * @property PresenceState $presence_state
 * @property Carbon|null $dnd_until
 * @property bool $dnd_schedule_enabled
 * @property string|null $dnd_starts_at
 * @property string|null $dnd_ends_at
 * @property Carbon|null $dnd_schedule_snoozed_until
 * @property Carbon|null $onboarding_completed_at
 * @property bool $is_tombstone
 * @property Carbon|null $deactivated_at
 * @property array<int, string>|null $collapsed_channel_sections
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string|null $avatar
 * @property-read UserStatusData|null $status
 * @property-read PresenceState $presence
 * @property-read UserDndData $dnd
 * @property-read Team|null $currentTeam
 * @property-read Team|null $ownerTeam
 * @property-read Collection<int, Team> $ownedTeams
 * @property-read Collection<int, Membership> $teamMemberships
 * @property-read Collection<int, Team> $teams
 * @property-read Collection<int, Channel> $channels
 * @property-read Collection<int, ChannelSection> $channelSections
 * @property-read Collection<int, DataExport> $dataExports
 * @property-read Collection<int, Passkey> $passkeys
 * @property-read Collection<int, UserGroup> $userGroups
 */
#[Appends(['avatar', 'status', 'presence', 'dnd'])]
#[Fillable(['name', 'email', 'avatar_url', 'pronouns', 'title', 'phone', 'timezone', 'locale', 'time_format', 'password', 'current_team_id', 'chime_sound', 'share_read_receipts', 'sidebar_position', 'presence_state', 'onboarding_completed_at', 'collapsed_channel_sections', 'is_tombstone'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token', 'avatar_url', 'avatar_path', 'status_emoji', 'status_text', 'status_expires_at', 'dnd_until', 'dnd_schedule_enabled', 'dnd_starts_at', 'dnd_ends_at', 'dnd_schedule_snoozed_until'])]
#[ObservedBy(UserObserver::class)]
class User extends Authenticatable implements HasLocalePreference, MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasTeams, HasUuids, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

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
     * email's Gravatar — routed through the first-party image proxy, so the
     * browser never talks to gravatar.com and no reader's IP leaks to it. Null
     * when there is no stored URL and Gravatar is disabled, and — with the `404`
     * default — a user without a Gravatar yields a URL that 404s so the frontend
     * cleanly falls back to its initials avatar.
     *
     * @return Attribute<covariant string|null, never>
     */
    protected function avatar(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->avatar_url ?? ImageProxy::url(Gravatar::url($this->email)));
    }

    /**
     * Whether the user's custom status is currently showing.
     *
     * This is the lazy half of expiry: a status whose `status_expires_at` has
     * passed reads as absent everywhere from the instant it lapses, without
     * waiting for {@see ClearExpiredUserStatuses} to null the columns and
     * broadcast the clear.
     */
    public function hasLiveStatus(): bool
    {
        if ($this->status_emoji === null) {
            return false;
        }

        return $this->status_expires_at === null || $this->status_expires_at->isFuture();
    }

    /**
     * Whether the user is in do-not-disturb right now.
     *
     * A manual pause whose `dnd_until` is still ahead reads as DND from the
     * instant it is set, and lapses on read the instant it passes — the same
     * lazy half of expiry as {@see hasLiveStatus()}, with the scheduled sweep
     * as the eager half that propagates the lapse to teammates.
     */
    public function isDndActive(): bool
    {
        if ($this->dnd_until?->isFuture()) {
            return true;
        }

        return $this->isInsideDndScheduleWindow();
    }

    /**
     * Whether the recurring quiet-hours window covers this instant.
     *
     * The bounds are wall-clock `HH:MM` strings compared in the user's own
     * timezone, so the window follows them when they travel. Start is
     * inclusive and end exclusive, and a window whose end precedes its start
     * wraps across midnight (22:00–07:00 covers the night, not an empty set).
     * A snooze still ahead of its lapse suppresses the window outright — it is
     * set to the instant the running window next closes, so the schedule
     * resumes on its own without a re-enable step.
     */
    private function isInsideDndScheduleWindow(): bool
    {
        if (! $this->dnd_schedule_enabled || $this->dnd_starts_at === null || $this->dnd_ends_at === null) {
            return false;
        }

        if ($this->dnd_schedule_snoozed_until?->isFuture()) {
            return false;
        }

        $now = now($this->timezone ?? config('app.timezone'))->format('H:i');

        if ($this->dnd_starts_at <= $this->dnd_ends_at) {
            return $now >= $this->dnd_starts_at && $now < $this->dnd_ends_at;
        }

        return $now >= $this->dnd_starts_at || $now < $this->dnd_ends_at;
    }

    /**
     * The instant the quiet-hours window covering this moment next closes, or
     * null when no window covers it.
     *
     * This is what a snooze suppresses the schedule until: an overnight window
     * entered before midnight closes tomorrow morning, its morning tail closes
     * today. Null outside the window (or while already snoozed) so a stale
     * request can never suppress a window that has not opened. Computed on the
     * user's own wall clock but returned in the app timezone, because Eloquent
     * persists a datetime's wall-clock reading without converting it first.
     */
    public function dndScheduleClosesAt(): ?CarbonInterface
    {
        if (! $this->isInsideDndScheduleWindow()) {
            return null;
        }

        $now = now($this->timezone ?? config('app.timezone'));

        $closes = $now->setTimeFromTimeString((string) $this->dnd_ends_at);

        // Inside the window the end is always ahead on the wall clock; an end
        // reading behind now means tonight's window closes tomorrow morning.
        if ($closes->lessThanOrEqualTo($now)) {
            $closes = $closes->addDay();
        }

        return $closes->setTimezone(config('app.timezone'));
    }

    /**
     * The user's own full do-not-disturb configuration.
     *
     * Appended to every serialisation of the model, which only ever reaches its
     * owner (the `auth.user` prop) — teammates read the curated
     * {@see UserData::$isDnd} boolean instead. The raw columns stay hidden so a
     * lapsed pause can never leak through them.
     *
     * @return Attribute<covariant UserDndData, never>
     */
    protected function dnd(): Attribute
    {
        return Attribute::get(fn (): UserDndData => UserDndData::forUser($this));
    }

    /**
     * The user's live custom status, or null when they have none.
     *
     * Appended to every serialisation of the model — the shared `auth.user`
     * prop above all — so the viewer's own menu reads it the same way teammates'
     * surfaces read {@see UserData::$status}. The raw columns stay hidden so a
     * lapsed status can never leak through them.
     *
     * @return Attribute<covariant UserStatusData|null, never>
     */
    protected function status(): Attribute
    {
        return Attribute::get(fn (): ?UserStatusData => UserStatusData::forUser($this));
    }

    /**
     * How reachable the user is right now, as teammates should see them.
     *
     * A manual away is an override and wins outright — that is the whole point
     * of setting it, and it survives reconnects because it lives on the row.
     * Otherwise the answer is derived from the user's live browser connections,
     * which is away only once every one of them has gone idle.
     */
    public function effectivePresence(): PresenceState
    {
        // Null only on a freshly-made instance the column default has not been
        // read back into yet, which is never away.
        if (($this->presence_state ?? PresenceState::Active) === PresenceState::Away) {
            return PresenceState::Away;
        }

        return app(PresenceRegistry::class)->aggregate($this->id);
    }

    /**
     * The user's effective presence, appended to every serialisation of the
     * model so the viewer's own `auth.user` prop carries the same answer that
     * {@see UserData::$presence} gives their teammates.
     *
     * @return Attribute<covariant PresenceState, never>
     */
    protected function presence(): Attribute
    {
        return Attribute::get(fn (): PresenceState => $this->effectivePresence());
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
            'time_format' => TimeFormat::class,
            'chime_sound' => ChimeSound::class,
            'share_read_receipts' => 'boolean',
            'sidebar_position' => SidebarPosition::class,
            'presence_state' => PresenceState::class,
            'dnd_until' => 'datetime',
            'dnd_schedule_enabled' => 'boolean',
            'dnd_schedule_snoozed_until' => 'datetime',
            'onboarding_completed_at' => 'datetime',
            'status_expires_at' => 'datetime',
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
     * The user's Sanctum API tokens.
     *
     * Narrows Sanctum's trait relation to the application's own token model so
     * a token's bound {@see PersonalAccessToken::team()} is visible to callers
     * and static analysis.
     *
     * @return MorphMany<PersonalAccessToken, $this>
     */
    public function tokens(): MorphMany
    {
        return $this->morphMany(PersonalAccessToken::class, 'tokenable');
    }

    /**
     * The human who created this account (set only for bots), so the
     * integrations surface can attribute a bot to its creator.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(self::class, 'created_by');
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
     * The messages this user has authored, newest first — used to surface a
     * bot's last-post time on the integrations surface.
     *
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->latest();
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
     * Get the mentionable user groups this user has been added to, across all of
     * their workspaces. Callers scope by team where that matters.
     *
     * @return BelongsToMany<UserGroup, $this>
     */
    public function userGroups(): BelongsToMany
    {
        return $this->belongsToMany(UserGroup::class, 'user_group_user')->withTimestamps();
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
