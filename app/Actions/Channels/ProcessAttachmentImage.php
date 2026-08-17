<?php

declare(strict_types=1);

namespace App\Actions\Channels;

use App\Models\Attachment;
use App\Support\Images\ImageProcessor;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Encoders\AutoEncoder;
use RuntimeException;
use Throwable;

final readonly class ProcessAttachmentImage
{
    public function __construct(private ImageProcessor $images) {}

    /**
     * Strip metadata from a raster image attachment in place and generate a
     * downscaled thumbnail beside it.
     *
     * Runs synchronously at upload so no un-stripped original is ever reachable
     * through the serve route (photo GPS rides in EXIF), and so the thumbnail
     * exists before the message that claims the file broadcasts. SVG and every
     * non-image type are left untouched — SVG is download-only, so it is never
     * decoded here. A file whose bytes cannot be decoded despite an image mime is
     * left as-is with no thumbnail rather than failing the upload.
     */
    public function handle(Attachment $attachment): void
    {
        if (! $attachment->isImage()) {
            return;
        }

        $disk = Storage::disk($attachment->disk);

        // The whole pipeline is guarded: a file that cannot be decoded, encoded,
        // or written (an unsupported/corrupt image, or a disk hiccup) is left as
        // uploaded with no thumbnail rather than failing the upload.
        try {
            // One line so the throw is attributed to it: a blob that vanished
            // between upload and processing cannot be staged in a test, and PCOV
            // would count a wrapped `?? throw` as an uncovered line of its own.
            $binary = $disk->get($attachment->blobPath()) ?? throw new RuntimeException('The uploaded file is no longer on the disk.');

            $image = $this->images->decode($binary);

            $this->images->stripMetadata($image);

            // Rewrite the original without its metadata (EXIF/GPS/XMP), keeping
            // the format and near-original quality; the lightbox serves it inline.
            $disk->put($attachment->blobPath(), (string) $image->encode(new AutoEncoder(quality: 90)));

            $width = $image->width();
            $height = $image->height();

            // Downscale the same (already stripped) image into the thumbnail.
            $max = (int) config('attachments.thumbnail_max_px');
            $image->scaleDown(width: $max, height: $max);

            $thumbPath = $this->thumbnailPath($attachment->blobPath());
            $disk->put($thumbPath, (string) $image->encode(new AutoEncoder(quality: 80)));

            $attachment->forceFill([
                'thumb_path' => $thumbPath,
                'width' => $width,
                'height' => $height,
                'size_bytes' => $disk->size($attachment->blobPath()),
            ])->save();
        } catch (Throwable) {
            return;
        }
    }

    /**
     * The thumbnail's path: a `thumbnails/` sibling of the original, keeping the
     * original basename (and extension, so it serves with the same content type).
     */
    private function thumbnailPath(string $originalPath): string
    {
        return dirname($originalPath).'/thumbnails/'.basename($originalPath);
    }
}
