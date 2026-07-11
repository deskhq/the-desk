<?php

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A member's message count for the top-contributors ranking.
 */
#[TypeScript]
class ContributorData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public int $count,
    ) {}
}
