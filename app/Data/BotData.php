<?php

namespace App\Data;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Carbon;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A bot identity as shown on the integrations surface — its channel and token
 * counts, who created it, and when it last posted (null if it never has).
 */
#[TypeScript]
class BotData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public int $channelsCount,
        public int $tokensCount,
        public ?MentionData $createdBy,
        public ?string $lastPostedAt,
    ) {}

    /**
     * Build the DTO from a bot user. Counts should be loaded via `withCount`
     * and the latest-message timestamp via `withMax`.
     */
    public static function fromModel(User $bot): self
    {
        $lastPostedAt = $bot->getAttribute('messages_max_created_at');

        return new self(
            id: $bot->id,
            name: $bot->name,
            channelsCount: (int) ($bot->channels_count ?? 0),
            tokensCount: (int) ($bot->tokens_count ?? 0),
            createdBy: $bot->creator ? MentionData::fromUser($bot->creator) : null,
            lastPostedAt: $lastPostedAt !== null ? Carbon::parse((string) $lastPostedAt)->toISOString() : null,
        );
    }

    /**
     * The team's bots for the integrations home, newest first.
     *
     * @return array<int, self>
     */
    public static function forTeam(Team $team): array
    {
        return $team->bots()
            ->with('creator')
            ->withCount(['channels', 'tokens'])
            ->withMax('messages', 'created_at')
            ->latest()
            ->get()
            ->map(fn (User $bot): self => self::fromModel($bot))
            ->all();
    }
}
