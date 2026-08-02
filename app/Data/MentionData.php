<?php

namespace App\Data;

use App\Models\User;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A person reduced to what naming them takes: the compact payload every
 * "who did this" list rides on — a body's @mentions, a reaction's reactors, a
 * poll's voters, a pin's author, a thread's participants.
 *
 * `avatar` is derived from the member's email (Gravatar) and is null when they
 * have none, which is the client's cue to fall back to their initials.
 */
#[TypeScript]
class MentionData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $avatar = null,
    ) {}

    /**
     * Build the DTO from a mentioned User model.
     */
    public static function fromUser(User $user): self
    {
        return new self(
            id: $user->id,
            name: $user->name,
            avatar: $user->avatar,
        );
    }
}
