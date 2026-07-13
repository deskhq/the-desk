<?php

declare(strict_types=1);

namespace App\Actions\Channels;

use App\Models\Attachment;
use Illuminate\Support\Facades\Storage;
use Imagick;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Encoders\AutoEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Throwable;

class ProcessAttachmentImage
{
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

        try {
            $image = $this->manager()->decodeBinary($disk->get($attachment->path));
        } catch (Throwable) {
            return;
        }

        $this->stripMetadata($image);

        // Rewrite the original without its metadata (EXIF/GPS/XMP), keeping the
        // format and near-original quality; the lightbox serves this file inline.
        $disk->put($attachment->path, (string) $image->encode(new AutoEncoder(quality: 90)));

        $width = $image->width();
        $height = $image->height();

        // Downscale the same (already stripped) image into the timeline thumbnail.
        $max = (int) config('attachments.thumbnail_max_px');
        $image->scaleDown(width: $max, height: $max);

        $thumbPath = $this->thumbnailPath($attachment->path);
        $disk->put($thumbPath, (string) $image->encode(new AutoEncoder(quality: 80)));

        $attachment->forceFill([
            'thumb_path' => $thumbPath,
            'width' => $width,
            'height' => $height,
            'size_bytes' => $disk->size($attachment->path),
        ])->save();
    }

    /**
     * The Intervention manager on the configured driver. Imagick by default (it
     * handles more formats and strips metadata precisely); GD is a fallback for
     * hosts without the Imagick extension.
     */
    private function manager(): ImageManager
    {
        return new ImageManager(
            config('attachments.image_driver') === 'gd' ? new GdDriver : new ImagickDriver,
        );
    }

    /**
     * Remove every embedded metadata profile. GD drops them on re-encode already;
     * Imagick preserves the EXIF profile, so strip it from each frame explicitly.
     */
    private function stripMetadata(ImageInterface $image): void
    {
        $native = $image->core()->native();

        if ($native instanceof Imagick) {
            foreach ($native as $frame) {
                $frame->stripImage();
            }
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
