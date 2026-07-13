<?php

namespace App\Data;

use App\Models\Attachment;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class AttachmentData extends Data
{
    public function __construct(
        public string $id,
        public string $filename,
        public string $mimeType,
        public int $sizeBytes,
        public ?int $width,
        public ?int $height,
        // Whether the client should render the file inline as an image. False for
        // SVG (download-only) and every non-image type.
        public bool $isImage,
        // The authorized download URL (never a filesystem URL); images are served
        // inline, everything else as a download.
        public string $url,
        // The authorized thumbnail URL for the timeline grid, or null when no
        // thumbnail was generated (SVG and every non-image type); the client then
        // falls back to the full-resolution `url`.
        public ?string $thumbUrl,
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
            url: $attachment->url,
            thumbUrl: $attachment->thumb_url,
        );
    }
}
