<?php

namespace App\Data;

use App\Models\Channel;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ChannelData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public string $visibility,
        public ?string $topic,
        public bool $isGeneral,
        public bool $isArchived,
        public int $unreadCount = 0,
        public int $mentionCount = 0,
    ) {}

    /**
     * Build the DTO from a Channel model.
     *
     * `unread_count` and `mention_count` are populated only when the channel was
     * loaded for the current user's sidebar; elsewhere they are absent and
     * default to zero.
     */
    public static function fromChannel(Channel $channel): self
    {
        return new self(
            id: $channel->id,
            name: $channel->name,
            slug: $channel->slug,
            visibility: $channel->visibility->value,
            topic: $channel->topic,
            isGeneral: $channel->isGeneral(),
            isArchived: $channel->isArchived(),
            unreadCount: (int) ($channel->getAttribute('unread_count') ?? 0),
            mentionCount: (int) ($channel->getAttribute('mention_count') ?? 0),
        );
    }
}
