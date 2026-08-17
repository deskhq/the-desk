<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\NameSlug;
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
final class UserGroup extends Model
{
    /** @use HasFactory<UserGroupFactory> */
    use HasFactory, HasUuids;

    /**
     * Slug base used when a group name carries no sluggable characters.
     */
    public const string FALLBACK_SLUG = 'group';

    /**
     * Keep a usable handle on the row however the group is written.
     *
     * The handle is what people type after `@`, so a blank one makes the group
     * unmentionable (issue #924). The form requests derive it from the name
     * when it is left blank; this is the backstop for every other writer.
     */
    #[\Override]
    protected static function boot(): void
    {
        parent::boot();

        self::saving(function (UserGroup $group): void {
            if (blank($group->slug)) {
                $group->slug = NameSlug::distinct($group->name, self::FALLBACK_SLUG);
            }
        });
    }

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
