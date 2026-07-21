<?php

namespace App\Data;

use App\Models\Team;
use App\Models\User;
use App\Models\UserGroup;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class UserGroupData extends Data
{
    /**
     * @param  array<int, MentionData>  $members
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public int $membersCount,
        public array $members,
    ) {}

    /**
     * Build the DTO from a UserGroup model, with its `members` eager-loaded.
     */
    public static function fromModel(UserGroup $group): self
    {
        return new self(
            id: $group->id,
            name: $group->name,
            slug: $group->slug,
            membersCount: $group->members->count(),
            members: $group->members
                ->map(fn (User $member): MentionData => MentionData::fromUser($member))
                ->all(),
        );
    }

    /**
     * The workspace's groups for the management page and the composer's mention
     * menu, alphabetically by handle.
     *
     * @return array<int, self>
     */
    public static function forTeam(Team $team): array
    {
        return $team->userGroups()
            ->with('members')
            ->orderBy('slug')
            ->get()
            ->map(fn (UserGroup $group): self => self::fromModel($group))
            ->all();
    }

    /**
     * The same list without the member roster — enough for the mention menu and
     * for resolving a `group:<id>` token to a pill, without shipping every
     * group's membership to every reader.
     *
     * @return array<int, self>
     */
    public static function mentionableForTeam(Team $team): array
    {
        return $team->userGroups()
            ->withCount('members')
            ->orderBy('slug')
            ->get()
            ->map(fn (UserGroup $group): self => new self(
                id: $group->id,
                name: $group->name,
                slug: $group->slug,
                membersCount: $group->members_count ?? 0,
                members: [],
            ))
            ->all();
    }
}
