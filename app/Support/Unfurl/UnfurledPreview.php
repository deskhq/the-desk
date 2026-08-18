<?php

declare(strict_types=1);

namespace App\Support\Unfurl;

/**
 * What a link unfurled to.
 *
 * A title is the one field that has to be there: a card with no name is nothing
 * worth rendering, which is why {@see Unfurler} reports its absence as no
 * preview at all rather than as a preview with an empty heading.
 */
final readonly class UnfurledPreview
{
    public function __construct(
        public string $title,
        public ?string $description,
        public ?string $image,
        public ?string $siteName,
    ) {}
}
