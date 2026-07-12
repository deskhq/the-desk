<?php

namespace App\Data;

use App\Models\CustomEmoji;
use App\Models\Team;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class CustomEmojiData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $url,
        public ?MentionData $createdBy,
        public string $createdAt,
    ) {}

    /**
     * Build the DTO from a CustomEmoji model. Its `creator` should be eager-loaded.
     */
    public static function fromModel(CustomEmoji $emoji): self
    {
        return new self(
            id: $emoji->id,
            name: $emoji->name,
            url: $emoji->url,
            createdBy: $emoji->creator ? MentionData::fromUser($emoji->creator) : null,
            createdAt: $emoji->created_at?->toISOString() ?? '',
        );
    }

    /**
     * The team's custom emoji for the registry page, newest first.
     *
     * @return array<int, self>
     */
    public static function forTeam(Team $team): array
    {
        return $team->customEmojis()
            ->with('creator')
            ->latest()
            ->get()
            ->map(fn (CustomEmoji $emoji): self => self::fromModel($emoji))
            ->all();
    }

    /**
     * The team's custom emoji as a flat `name => url` map for rendering shortcodes
     * in message bodies and reaction pills. A revoked emoji is simply absent, so
     * its `:name:` token falls back to plain text.
     *
     * @return array<string, string>
     */
    public static function mapForTeam(Team $team): array
    {
        return $team->customEmojis()
            ->get()
            ->mapWithKeys(fn (CustomEmoji $emoji): array => [$emoji->name => $emoji->url])
            ->all();
    }
}
