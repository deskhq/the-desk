<?php

namespace App\Data;

use App\Models\User;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

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
