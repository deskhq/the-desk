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
    ) {}

    /**
     * Build the DTO from a Channel model.
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
        );
    }
}
