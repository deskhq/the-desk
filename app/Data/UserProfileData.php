<?php

namespace App\Data;

use App\Models\Membership;
use App\Models\User;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class UserProfileData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
        public ?string $role,
        public ?string $roleLabel,
        public ?string $memberSince,
        public bool $isYou,
    ) {}

    /**
     * Build the profile DTO for a member of a team as seen by the viewer.
     *
     * The role and join date are read from the member's team membership pivot,
     * so it must belong to the team the profile is scoped to.
     */
    public static function forMember(User $member, Membership $membership, User $viewer): self
    {
        return new self(
            id: $member->id,
            name: $member->name,
            email: $member->email,
            role: $membership->role->value,
            roleLabel: $membership->role->label(),
            memberSince: $membership->created_at?->toIso8601String(),
            isYou: $member->is($viewer),
        );
    }
}
