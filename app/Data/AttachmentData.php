<?php

namespace App\Data;

use App\Enums\AttachmentSource;
use App\Models\Attachment;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class AttachmentData extends Data
{
    public function __construct(
        public string $id,
        // The original upload filename, or null for a remote (Giphy) attachment
        // which has no uploaded file — the client labels it from `description`.
        public ?string $filename,
        public string $mimeType,
        public int $sizeBytes,
        public ?int $width,
        public ?int $height,
        // Whether the client should render the file inline as an image. False for
        // SVG (download-only) and every non-image type. A Giphy GIF is `image/gif`,
        // so it renders inline through the same path with no special-casing.
        public bool $isImage,
        // The attachment's provenance: an operator-hosted upload or a remote
        // (hotlinked) Giphy GIF. The client renders both identically.
        public AttachmentSource $source,
        // The media URL. For an upload this is the authorized download route
        // (never a filesystem URL); for a Giphy GIF it is the CDN media URL,
        // hotlinked directly.
        public string $url,
        // The authorized thumbnail URL for the timeline grid, or null when no
        // thumbnail was generated (SVG, every non-image type, and Giphy GIFs);
        // the client then falls back to the full-resolution `url`.
        public ?string $thumbUrl,
        // The image's alt text: the Giphy content description for a GIF, or null
        // for an upload (which has no provider-supplied description).
        public ?string $description,
    ) {}

    /**
     * Build the DTO from an attachment row.
     *
     * The attachment's `channel` (and its `team`) should be eager-loaded so the
     * download `url` resolves without an N+1; the message payload's relation set
     * pulls them through for every attached file.
     */
    public static function fromAttachment(Attachment $attachment): self
    {
        return new self(
            id: $attachment->id,
            filename: $attachment->original_filename,
            mimeType: $attachment->mime_type,
            sizeBytes: $attachment->size_bytes,
            width: $attachment->width,
            height: $attachment->height,
            isImage: $attachment->isImage(),
            source: $attachment->source,
            url: $attachment->url,
            thumbUrl: $attachment->thumb_url,
            description: $attachment->description,
        );
    }
}
