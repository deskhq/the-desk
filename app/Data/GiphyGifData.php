<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class GiphyGifData extends Data
{
    public function __construct(
        // The opaque Giphy id. The only thing the client sends back to claim a
        // GIF; the server re-resolves it authoritatively (never trusting a URL).
        public string $id,
        // The animated media URL (Giphy `fixed_height` rendition): the URL stored
        // on the attachment and rendered inline in the timeline.
        public string $url,
        // A smaller animated rendition for the picker grid tile, keeping the grid
        // light while the full media is only fetched once a GIF is picked/sent.
        public string $previewUrl,
        // The media pixel dimensions, so the picker grid and (once sent) the
        // virtualized timeline both reserve stable layout.
        public int $width,
        public int $height,
        // The Giphy content description, used as the rendered `<img alt>`.
        public ?string $description,
    ) {}
}
