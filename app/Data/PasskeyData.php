<?php

namespace App\Data;

use Laravel\Passkeys\Passkey;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class PasskeyData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $authenticator,
        public ?string $lastUsedAt,
        public string $createdAt,
    ) {}

    /**
     * Build the DTO from a stored passkey. `authenticator` is the friendly
     * device label resolved from the credential's AAGUID (null when unknown).
     */
    public static function fromModel(Passkey $passkey): self
    {
        return new self(
            id: (string) $passkey->id,
            name: $passkey->name,
            authenticator: $passkey->authenticator,
            lastUsedAt: $passkey->last_used_at?->toIso8601String(),
            createdAt: $passkey->created_at->toIso8601String(),
        );
    }
}
