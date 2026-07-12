<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The message count for a single calendar day in the messages-per-day series.
 */
#[TypeScript]
class DailyMessageCountData extends Data
{
    public function __construct(
        public string $date,
        public int $count,
    ) {}
}
