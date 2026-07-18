<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class GiphySearchData extends Data
{
    /**
     * @param  array<int, GiphyGifData>  $results
     */
    public function __construct(
        // The GIFs for this page of results.
        public array $results,
        // The offset to request for the next page, or null when the results are
        // exhausted — drives the picker's infinite scroll.
        public ?int $nextOffset,
    ) {}
}
