<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Sso\ProvisionSsoUser;
use App\Data\TeamPermissions;
use App\Data\UserTeam;
use App\Enums\TeamPermission;
use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\Team;
use App\Models\UserGroup;
use App\Policies\TeamPolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use RuntimeException;

trait HasTeams
{
    /**
     * The role this user holds on a team, memoised for the length of a single
     * {@see holdingTeamRole()} window and no longer.
     *
     * A projection asks the gate about one membership fifteen times over, and
     * each ability would otherwise resolve the row again. It is deliberately
     * not kept for the request: a role rewritten between two projections must
     * be answered from the database, not from a copy taken before the write.
     *
     * @var array<string, TeamRole|null>
     */
    private array $projectedTeamRoles = [];

    /**
     * Get all of the teams the user belongs to.
     *
     * @return BelongsToMany<Team, $this>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_members', 'user_id', 'team_id')
            ->withPivot(['role'])
            ->withTimestamps();
    }

    /**
     * Get all of the teams the user owns.
     *
     * @return HasManyThrough<Team, Membership, $this>
     */
    public function ownedTeams(): HasManyThrough
    {
        return $this->hasManyThrough(
            Team::class,
            Membership::class,
            'user_id',
            'id',
            'id',
            'team_id',
        )->where('team_members.role', TeamRole::Owner->value);
    }

    /**
     * Get all of the memberships for the user.
     *
     * @return HasMany<Membership, $this>
     */
    public function teamMemberships(): HasMany
    {
        return $this->hasMany(Membership::class, 'user_id');
    }

    /**
     * Get the user's current team.
     *
     * @return BelongsTo<Team, $this>
     */
    public function currentTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'current_team_id');
    }

    /**
     * Get the user's personal team.
     */
    public function personalTeam(): ?Team
    {
        return $this->teams()
            ->where('is_personal', true)
            ->first();
    }

    /**
     * The user's personal team, which every account is created with.
     *
     * Both account-creation paths open one ({@see CreateNewUser},
     * {@see ProvisionSsoUser}) and no path deletes it while the
     * account lives — leaving and deleting are both refused for a personal team.
     * So the workspace somebody is moved back to when they lose another one is
     * always there, and an account without one is a broken account rather than a
     * case to route around.
     */
    public function personalTeamOrFail(): Team
    {
        return $this->personalTeam() ?? throw new RuntimeException("User {$this->id} has no personal team to fall back to.");
    }

    /**
     * Switch to the given team.
     */
    public function switchTeam(Team $team): bool
    {
        if (! $this->belongsToTeam($team)) {
            return false;
        }

        $this->update(['current_team_id' => $team->id]);
        $this->setRelation('currentTeam', $team);

        URL::defaults(['current_team' => $team->slug]);

        return true;
    }

    /**
     * Determine if the user belongs to the given team.
     */
    public function belongsToTeam(Team $team): bool
    {
        return $this->teams()->where('teams.id', $team->id)->exists();
    }

    /**
     * Determine if the given team is the user's current team.
     */
    public function isCurrentTeam(Team $team): bool
    {
        return $this->current_team_id === $team->id;
    }

    /**
     * Determine if the user is the owner of the given team.
     */
    public function ownsTeam(Team $team): bool
    {
        return $this->teamRole($team) === TeamRole::Owner;
    }

    /**
     * Get the user's role on the given team.
     */
    public function teamRole(Team $team): ?TeamRole
    {
        if (array_key_exists($team->id, $this->projectedTeamRoles)) {
            return $this->projectedTeamRoles[$team->id];
        }

        return $this->teamMemberships()
            ->where('team_id', $team->id)
            ->first()
            ?->role;
    }

    /**
     * Get the user's teams as a collection of UserTeam objects.
     *
     * The member counts are batched onto the list query rather than counted per
     * row: every tile carries its workspace's size, and a `count()` per team is
     * an N+1 that only shows itself on an account with several of them.
     *
     * @return Collection<int, UserTeam>
     */
    public function toUserTeams(bool $includeCurrent = false): Collection
    {
        return $this->teams()
            ->withCount('members')
            ->get()
            ->reject(fn (Team $team): bool => ! $includeCurrent && $this->isCurrentTeam($team))
            ->map(fn (Team $team): UserTeam => $this->toUserTeam($team))
            ->values();
    }

    /**
     * Get the user's team as a UserTeam object.
     *
     * The size is taken off the row when {@see toUserTeams()} has already
     * batched it there, and counted only for a team handed over on its own.
     */
    public function toUserTeam(Team $team): UserTeam
    {
        $role = $this->teamRole($team);

        return new UserTeam(
            id: $team->id,
            name: $team->name,
            slug: $team->slug,
            isPersonal: $team->is_personal,
            role: $role?->value,
            roleLabel: $role?->label(),
            membersCount: $team->members_count ?? $team->members()->count(),
            isCurrent: $this->isCurrentTeam($team),
        );
    }

    /**
     * Get the standard permissions for a team as a TeamPermissions object.
     *
     * Every field is projected from the ability that already decides it, so the
     * page draws exactly what the server would let through. Re-stating those
     * rules here is what let `canDeleteTeam` say yes on a personal workspace
     * that {@see TeamPolicy::delete()} refuses; `TeamPermissionsTest` pins the
     * two together field by field.
     */
    public function toTeamPermissions(Team $team): TeamPermissions
    {
        $gate = Gate::forUser($this);

        // One lookup answers all fifteen abilities below, which each resolve the
        // same membership.
        return $this->holdingTeamRole($team, fn (): TeamPermissions => new TeamPermissions(
            canUpdateTeam: $gate->allows('update', $team),
            canDeleteTeam: $gate->allows('delete', $team),
            canAddMember: $gate->allows('addMember', $team),
            canUpdateMember: $gate->allows('updateMember', $team),
            canRemoveMember: $gate->allows('removeMember', $team),
            canCreateInvitation: $gate->allows('inviteMember', $team),
            canCancelInvitation: $gate->allows('cancelInvitation', $team),
            canTransferOwnership: $gate->allows('transferOwnership', $team),
            canViewAudit: $gate->allows('viewAudit', $team),
            canViewSecurityLog: $gate->allows('viewSecurityLog', $team),
            canViewAnalytics: $gate->allows('viewAnalytics', $team),
            canViewDeletedChannels: $gate->allows('viewDeletedChannels', $team),
            canManageEmojis: $gate->allows('manageEmojis', $team),
            canManageIntegrations: $gate->allows('manageIntegrations', $team),
            canManageUserGroups: $gate->allows('viewAny', [UserGroup::class, $team]),
        ));
    }

    /**
     * Run the callback with this user's role on the team held, so every ability
     * it asks about resolves the one membership once instead of per question.
     *
     * Re-entrant, and that is the point of it being reachable at all: a caller
     * that asks two projections of the same membership — the fifteen team
     * abilities in {@see toTeamPermissions()} and the channel-creation ones
     * beside them — pays one lookup for both, because the inner call reuses the
     * role the outer one holds and leaves releasing it to that outer call.
     *
     * The role is released as soon as the outermost call returns. It is
     * deliberately not kept for the request: a role rewritten between two
     * derivations must be answered from the database, not from a copy taken
     * before the write.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function holdingTeamRole(Team $team, callable $callback): mixed
    {
        if (array_key_exists($team->id, $this->projectedTeamRoles)) {
            return $callback();
        }

        $this->projectedTeamRoles[$team->id] = $this->teamRole($team);

        try {
            return $callback();
        } finally {
            unset($this->projectedTeamRoles[$team->id]);
        }
    }

    public function fallbackTeam(?Team $excluding = null): ?Team
    {
        return $this->teams()
            ->when($excluding, fn ($query, Team $excluded) => $query->where('teams.id', '!=', $excluded->id))
            ->orderByRaw('LOWER(teams.name)')
            ->first();
    }

    /**
     * Drop the user from every mentionable group of the given team.
     *
     * Group membership presupposes workspace membership, so this runs wherever a
     * membership ends — a later group mention must not reach someone who has
     * left. The pivot itself only cascades on user *deletion*, which is a
     * different thing entirely.
     */
    public function leaveUserGroups(Team $team): void
    {
        $this->userGroups()->detach($team->userGroups()->pluck('id'));
    }

    /**
     * Determine if the user has the given permission on the team.
     */
    public function hasTeamPermission(Team $team, TeamPermission $permission): bool
    {
        return $this->teamRole($team)?->hasPermission($permission) ?? false;
    }
}
