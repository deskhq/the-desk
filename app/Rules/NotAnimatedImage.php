<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Translation\PotentiallyTranslatedString;

class NotAnimatedImage implements ValidationRule
{
    /**
     * Reject animated raster images (multi-frame GIF, APNG, animated WebP).
     *
     * Avatars are static only, so an animated upload is refused before it ever
     * reaches the image processor. Detection is a format-gated byte-signature
     * scan — no image library or extension is required, and each check only runs
     * once the file's magic bytes confirm the format, so a JPEG that happens to
     * contain an "ANIM" or "acTL" byte run in its scan data is never mistaken for
     * animated.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            return;
        }

        $bytes = (string) file_get_contents($value->getRealPath());

        if ($this->isAnimated($bytes)) {
            $fail(__('Animated images aren’t supported. Use a static JPEG, PNG or WebP.'));
        }
    }

    /**
     * Whether the bytes are an animated image of a format we accept.
     */
    private function isAnimated(string $bytes): bool
    {
        if ($this->isAnimatedGif($bytes)) {
            return true;
        }
        if ($this->isAnimatedPng($bytes)) {
            return true;
        }

        return $this->isAnimatedWebp($bytes);
    }

    /**
     * A GIF is animated when it carries more than one Graphic Control Extension
     * (`0x21 0xF9`) — one precedes each frame.
     */
    private function isAnimatedGif(string $bytes): bool
    {
        if (! str_starts_with($bytes, 'GIF8')) {
            return false;
        }

        return substr_count($bytes, "\x21\xF9") > 1;
    }

    /**
     * An APNG is a PNG that carries an animation-control (`acTL`) chunk. To be a
     * real APNG marker the `acTL` chunk must precede the first image data
     * (`IDAT`); an `acTL` byte run inside compressed IDAT data is not one.
     */
    private function isAnimatedPng(string $bytes): bool
    {
        if (! str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            return false;
        }

        $actl = strpos($bytes, 'acTL');

        if ($actl === false) {
            return false;
        }

        $idat = strpos($bytes, 'IDAT');

        return $idat === false || $actl < $idat;
    }

    /**
     * An animated WebP is an extended (`VP8X`) RIFF/WEBP container with the
     * animation flag (bit 1 of the VP8X flags byte, 8 bytes past the chunk id)
     * set — the canonical signal, unlike an `ANIM` byte run that could appear in
     * frame payload data.
     */
    private function isAnimatedWebp(string $bytes): bool
    {
        if (! str_starts_with($bytes, 'RIFF') || substr($bytes, 8, 4) !== 'WEBP') {
            return false;
        }

        $vp8x = strpos($bytes, 'VP8X');

        if ($vp8x === false) {
            return false;
        }

        return (ord(substr($bytes, $vp8x + 8, 1)) & 0x02) !== 0;
    }
}
