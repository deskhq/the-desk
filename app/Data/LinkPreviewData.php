<?php

namespace App\Data;

use App\Models\MessageLinkPreview;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class LinkPreviewData extends Data
{
    public function __construct(
        public string $url,
        public string $status,
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
     */
    public static function fromModel(MessageLinkPreview $preview): self
    {
        return new self(
            url: $preview->url,
            status: $preview->status->value,
            title: $preview->title,
            description: $preview->description,
            imageUrl: $preview->image_url,
            siteName: $preview->site_name,
        );
    }
}
