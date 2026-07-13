<?php

namespace App\Data;

use App\Models\MessagePin;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class PinData extends Data
{
    public function __construct(
        public MentionData $pinnedBy,
        public string $pinnedAt,
    ) {}

    /**
     * Build the DTO from a MessagePin model.
     *
     * The pin's `pinnedBy` user should be eager-loaded (it rides on the message
     * payload's relation set) so the "Pinned by :name" attribution avoids an N+1.
     */
    public static function fromPin(MessagePin $pin): self
    {
        return new self(
            pinnedBy: MentionData::fromUser($pin->pinnedBy),
            pinnedAt: $pin->created_at->toIso8601String(),
        );
    }
}
