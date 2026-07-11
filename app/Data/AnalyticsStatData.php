<?php

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A single headline metric on the analytics dashboard. The optional fields let
 * one shape drive every tile: a "value of total" pair, an absolute change, a
 * percentage change, or a secondary figure — each tile fills only what it uses.
 */
#[TypeScript]
class AnalyticsStatData extends Data
{
    public function __construct(
        public int $value,
        public ?int $total = null,
        public ?int $delta = null,
        public ?int $deltaPercent = null,
        public ?int $secondary = null,
    ) {}
}
