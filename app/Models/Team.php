<?php

namespace App\Models;

use App\Concerns\GeneratesUniqueTeamSlugs;
use App\Enums\ChannelCreationPolicy;
use App\Enums\ChannelVisibility;
use App\Enums\TeamRole;
use App\Enums\UserType;
use Database\Factories\TeamFactory;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property bool $is_personal
 * @property ChannelCreationPolicy $public_channel_creation_policy
 * @property ChannelCreationPolicy $private_channel_creation_policy
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, TeamInvitation> $invitations
 * @property-read Collection<int, Membership> $memberships
 * @property-read Collection<int, User> $members
 * @property-read Collection<int, Channel> $channels
 * @property-read Collection<int, CustomEmoji> $customEmojis
 * @property-read Collection<int, UserGroup> $userGroups
 * @property-read Collection<int, WebhookSubscription> $webhookSubscriptions
 */
#[Fillable(['name', 'slug', 'is_personal', 'public_channel_creation_policy', 'private_channel_creation_policy'])]
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use GeneratesUniqueTeamSlugs, HasFactory, HasUuids, SoftDeletes;

    /**
     * The model's default attribute values.
     *
     * Mirrors the columns' database defaults so a freshly created team answers
     * {@see creationPolicyFor()} without being reloaded — `create()` leaves an
     * attribute the database filled in absent from the in-memory model.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'public_channel_creation_policy' => ChannelCreationPolicy::Members->value,
        'private_channel_creation_policy' => ChannelCreationPolicy::Members->value,
    ];

    /**
     * Bootstrap the model and its traits.
     */
    #[\Override]
    protected static function boot(): void
    {
        parent::boot();

        /**
         * Keep the slug present and in step with the name on every write.
         *
         * The slug is the route key ({@see getRouteKeyName()}), so a blank one
         * makes the team unreachable with no UI path back to it (issue #921).
         * Guarding on `saving` rather than on `creating`/`updating` covers the
         * three ways a blank slug could otherwise be persisted: a create with
         * no slug, a rename, and a slug explicitly blanked without a rename.
         */
        static::saving(function (Team $team): void {
            if (blank($team->slug) || ($team->exists && $team->isDirty('name'))) {
                $team->slug = static::generateUniqueTeamSlug(
                    (string) $team->name,
                    $team->exists ? $team->id : null,
                );
            }
        });
    }

    /**
     * Get the team owner.
     */
    public function owner(): ?Model
    {
        return $this->members()
            ->wherePivot('role', TeamRole::Owner->value)
            ->first();
    }

    /**
     * Get all members of this team.
     *
     * @return BelongsToMany<User, $this, Membership, 'pivot'>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_members', 'team_id', 'user_id')
            ->using(Membership::class)
            ->withPivot(['role'])
            ->withTimestamps();
    }

    /**
     * SQL ranking a membership by role, most privileged first.
     *
     * Spelled out rather than folded from {@see TeamRole::level()} because raw
     * SQL has to stay a literal expression to be provably injection-free, and a
     * value built at runtime is not. That duplicates the hierarchy, so the test
     * "the team edit page orders every role by its hierarchy level" walks
     * TeamRole::cases() and fails if a new role is added without a rank here.
     */
    private const string ROLE_HIERARCHY_SQL = 'case team_members.role'
        ." when '".TeamRole::Owner->value."' then 0"
        ." when '".TeamRole::Admin->value."' then 1"
        ." when '".TeamRole::Member->value."' then 2"
        .' else 3 end';

    /**
     * Get all members of this team in a stable, total order.
     *
     * {@see members()} carries no ORDER BY, so Postgres is free to hand the rows
     * back in a different order on every load and a rendered roster reshuffles
     * itself between two visits to the same page (issue #722). This orders the
     * most privileged roles first, then case-insensitively by name, with the id
     * as the tie-break that makes the order total.
     *
     * @return BelongsToMany<User, $this, Membership, 'pivot'>
     */
    public function roster(): BelongsToMany
    {
        return $this->members()
            ->orderByRaw(self::ROLE_HIERARCHY_SQL)
            ->orderByRaw('lower(users.name)')
            ->orderBy('users.id');
    }

    /**
     * Get all memberships for this team.
     *
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * Get all invitations for this team.
     *
     * @return HasMany<TeamInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(TeamInvitation::class);
    }

    /**
     * Get all channels belonging to this team.
     *
     * @return HasMany<Channel, $this>
     */
    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class);
    }

    /**
     * Get the team's outgoing-webhook subscriptions.
     *
     * @return HasMany<WebhookSubscription, $this>
     */
    public function webhookSubscriptions(): HasMany
    {
        return $this->hasMany(WebhookSubscription::class);
    }

    /**
     * Get the bot identities scoped to this workspace.
     *
     * Bots are {@see UserType::Bot} users referenced through `owner_team_id`
     * (not a team_members pivot), so they stay out of seat counts and rosters.
     *
     * @return HasMany<User, $this>
     */
    public function bots(): HasMany
    {
        return $this->hasMany(User::class, 'owner_team_id')
            ->where('type', UserType::Bot->value);
    }

    /**
     * Get the team's incoming webhooks.
     *
     * @return HasMany<IncomingWebhook, $this>
     */
    public function incomingWebhooks(): HasMany
    {
        return $this->hasMany(IncomingWebhook::class);
    }

    /**
     * Get all custom emoji registered in this workspace.
     *
     * @return HasMany<CustomEmoji, $this>
     */
    public function customEmojis(): HasMany
    {
        return $this->hasMany(CustomEmoji::class);
    }

    /**
     * Get the mentionable user groups curated in this workspace.
     *
     * @return HasMany<UserGroup, $this>
     */
    public function userGroups(): HasMany
    {
        return $this->hasMany(UserGroup::class);
    }

    /**
     * Get the audit-log and security-event exports requested for this workspace.
     *
     * @return HasMany<AuditExport, $this>
     */
    public function auditExports(): HasMany
    {
        return $this->hasMany(AuditExport::class);
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
            'is_personal' => 'boolean',
            'public_channel_creation_policy' => ChannelCreationPolicy::class,
            'private_channel_creation_policy' => ChannelCreationPolicy::class,
        ];
    }

    /**
     * Get the policy governing who may create a channel of the given visibility.
     */
    public function creationPolicyFor(ChannelVisibility $visibility): ChannelCreationPolicy
    {
        return $visibility === ChannelVisibility::Private
            ? $this->private_channel_creation_policy
            : $this->public_channel_creation_policy;
    }

    /**
     * Get the workspace's public channels that every new member is joined to.
     *
     * The protected #general is a default in code rather than by flag, so it is
     * matched by slug alongside whatever admins have marked. Archived and
     * deleted channels are excluded — the trait's global scope drops the latter
     * — so a default that has since been retired quietly stops applying instead
     * of dropping newcomers into a read-only room.
     *
     * @return HasMany<Channel, $this>
     */
    public function defaultChannels(): HasMany
    {
        return $this->channels()
            ->where('visibility', ChannelVisibility::Public->value)
            ->whereNull('archived_at')
            ->where(fn (Builder $query): Builder => $query
                ->where('is_default', true)
                ->orWhere('slug', Channel::GENERAL_SLUG));
    }

    /**
     * Get the route key for the model.
     */
    #[\Override]
    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
