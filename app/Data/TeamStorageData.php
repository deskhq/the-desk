<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A workspace's storage footprint against its configured quota. Only built while
 * the quota is on, so `quotaBytes` is always positive and `percent` always has a
 * denominator; it is uncapped, so an instance whose quota was lowered below its
 * existing usage reports the real overshoot rather than a flat 100%.
 */
#[TypeScript]
final class TeamStorageData extends Data
{
    public function __construct(
        public int $usedBytes,
        public int $quotaBytes,
        public int $percent,
    ) {}
}
