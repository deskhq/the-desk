<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A channel's message count for the most-active-channels ranking.
 */
#[TypeScript]
class ChannelActivityData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public int $count,
    ) {}
}
