<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The cumulative member total at the end of a single month in the growth series.
 * `month` is the first day of the month as an ISO date, for the chart's x-axis.
 */
#[TypeScript]
class MonthlyMemberCountData extends Data
{
    public function __construct(
        public string $month,
        public int $total,
    ) {}
}
