<?php

namespace App\Data;

use App\Models\ChannelSection;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ChannelSectionData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public int $position,
        public bool $collapsed,
    ) {}

    /**
     * Build the DTO from a ChannelSection model for the sidebar prop.
     */
    public static function fromSection(ChannelSection $section): self
    {
        return new self(
            id: $section->id,
            name: $section->name,
            position: $section->position,
            collapsed: $section->collapsed,
        );
    }
}
