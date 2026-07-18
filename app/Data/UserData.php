<?php

namespace App\Data;

use App\Models\User;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class UserData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $avatar = null,
        // Whether this author is a bot, so every surface that renders a user
        // (message rows, the channel member facepile) can mark it with the bot
        // badge and squared avatar, and the composer can exclude it from @mention
        // autocomplete.
        public bool $isBot = false,
    ) {}

    /**
     * Build the DTO from a User model.
     */
    public static function fromUser(User $user): self
    {
        return new self(
            id: $user->id,
            name: $user->name,
            avatar: $user->avatar,
            isBot: $user->isBot(),
        );
    }
}
