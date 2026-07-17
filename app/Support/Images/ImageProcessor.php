<?php

declare(strict_types=1);

namespace App\Support\Images;

use Imagick;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;

class ImageProcessor
{
    /**
     * Decode raw image bytes into an editable image on the configured driver.
     */
    public function decode(string $binary): ImageInterface
    {
        return $this->manager()->decodeBinary($binary);
    }

    /**
     * Remove every embedded metadata profile (EXIF/GPS/XMP). GD drops them on
     * re-encode already; Imagick preserves the EXIF profile, so strip it from
     * each frame explicitly.
     */
    public function stripMetadata(ImageInterface $image): void
    {
        $native = $image->core()->native();

        if ($native instanceof Imagick) {
            foreach ($native as $frame) {
                $frame->stripImage();
            }
        }
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
}
