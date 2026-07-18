<?php

namespace App\Data;

use App\Models\PersonalAccessToken;
use App\Models\User;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A bot's API token as shown on the bot detail page — its name, granted scopes,
 * and usage timestamps. The token value itself is never included (it is shown
 * once at mint time and only its hash is stored).
 */
#[TypeScript]
class BotTokenData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        /** @var array<int, string> */
        public array $abilities,
        public ?string $lastUsedAt,
        public string $createdAt,
    ) {}

    /**
     * Build the DTO from a token model.
     */
    public static function fromModel(PersonalAccessToken $token): self
    {
        /** @var array<int, string> $abilities */
        $abilities = $token->abilities ?? [];

        return new self(
            id: (string) $token->getKey(),
            name: $token->name,
            abilities: array_values($abilities),
            lastUsedAt: $token->last_used_at?->toISOString(),
            createdAt: $token->created_at?->toISOString() ?? '',
        );
    }

    /**
     * The bot's tokens, newest first.
     *
     * @return array<int, self>
     */
    public static function forBot(User $bot): array
    {
        return $bot->tokens()
            ->latest()
            ->get()
            ->map(fn (PersonalAccessToken $token): self => self::fromModel($token))
            ->all();
    }
}
