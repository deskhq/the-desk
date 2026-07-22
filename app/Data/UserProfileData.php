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
        public ?string $avatar,
        public ?string $pronouns,
        public ?string $title,
        public ?string $phone,
        public ?string $timezone,
        public ?string $role,
        public ?string $roleLabel,
        public ?string $memberSince,
        public bool $isYou,
        /** The member's live custom status, shown in full on the card and page. */
        public ?UserStatusData $status = null,
        // Whether the member is in do-not-disturb right now — the card names
        // the state without exposing when it ends. Same curated boolean as
        // UserData::$isDnd; the pause instant and schedule stay private.
        public bool $isDnd = false,
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
            avatar: $member->avatar,
            pronouns: $member->pronouns,
            title: $member->title,
            phone: $member->phone,
            timezone: $member->timezone,
            role: $membership->role->value,
            roleLabel: $membership->role->label(),
            memberSince: $membership->created_at?->toIso8601String(),
            isYou: $member->is($viewer),
            status: UserStatusData::forUser($member),
            isDnd: $member->isDndActive(),
        );
    }
}
