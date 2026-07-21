<?php

namespace App\Models;

use Database\Factories\UserGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $team_id
 * @property string $name
 * @property string $slug
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read Collection<int, User> $members
 * @property-read int|null $members_count
 */
#[Fillable(['team_id', 'name', 'slug'])]
class UserGroup extends Model
{
    /** @use HasFactory<UserGroupFactory> */
    use HasFactory, HasUuids;

    /**
     * Get the team this group belongs to. A group is workspace-scoped and can be
     * mentioned from any channel of that workspace.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the group's members — a static, explicitly curated list rather than a
     * role-derived one. Every member is also a member of the group's team.
     *
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_group_user')->withTimestamps();
    }
}
