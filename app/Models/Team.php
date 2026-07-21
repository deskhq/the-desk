<?php

namespace App\Models;

use App\Concerns\GeneratesUniqueTeamSlugs;
use App\Enums\TeamRole;
use App\Enums\UserType;
use Database\Factories\TeamFactory;
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
#[Fillable(['name', 'slug', 'is_personal'])]
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use GeneratesUniqueTeamSlugs, HasFactory, HasUuids, SoftDeletes;

    /**
     * Bootstrap the model and its traits.
     */
    #[\Override]
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Team $team): void {
            if (empty($team->slug)) {
                $team->slug = static::generateUniqueTeamSlug($team->name);
            }
        });

        static::updating(function (Team $team): void {
            if ($team->isDirty('name')) {
                $team->slug = static::generateUniqueTeamSlug($team->name, $team->id);
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
        ];
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
