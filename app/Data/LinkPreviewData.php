<?php

namespace App\Data;

use App\Enums\LinkPreviewStatus;
use App\Models\MessageLinkPreview;
use App\Support\Images\ImageProxy;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * An unfurled link preview attached to a message: `pending` while the queued job
 * fetches the Open Graph metadata (the card renders as a skeleton) and `ready`
 * once it resolves. A failed preview is dropped server-side, so `failed` never
 * reaches the client, and every metadata field stays null until the preview is
 * ready — and for any tag the linked page omits.
 */
#[TypeScript]
class LinkPreviewData extends Data
{
    public function __construct(
        public string $url,
        public LinkPreviewStatus $status,
        public ?string $title,
        public ?string $description,
        public ?string $imageUrl,
        public ?string $siteName,
    ) {}

    /**
     * Build the DTO from a link-preview row.
     *
     * `status` is `pending` while the queued unfurl runs (the client shows a
     * skeleton) and `ready` once the metadata resolves. Failed rows are filtered
     * out upstream in {@see MessageData::fromMessage()} so they never reach here.
     *
     * `imageUrl` is rewritten to the first-party image proxy: the thumbnail was
     * scraped from whatever site was linked, and hotlinking it would leak every
     * reader's IP to that site and force `img-src` to allow any HTTPS host.
     */
    public static function fromModel(MessageLinkPreview $preview): self
    {
        return new self(
            url: $preview->url,
            status: $preview->status,
            title: $preview->title,
            description: $preview->description,
            imageUrl: ImageProxy::url($preview->image_url),
            siteName: $preview->site_name,
        );
    }
}
