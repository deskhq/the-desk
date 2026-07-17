<?php

use App\Rules\NotAnimatedImage;
use Illuminate\Http\UploadedFile;

/**
 * Run the rule against a file carrying the given bytes and return the failure
 * message, or null when the rule passes.
 */
function runNotAnimatedRule(string $filename, string $bytes): ?string
{
    $file = UploadedFile::fake()->createWithContent($filename, $bytes);

    $message = null;
    (new NotAnimatedImage)->validate('photo', $file, function (string $error) use (&$message): void {
        $message = $error;
    });

    return $message;
}

test('it rejects an animated GIF', function (): void {
    // A GIF89a header with two Graphic Control Extension blocks (one per frame).
    $bytes = 'GIF89a'.str_repeat("\x21\xF9\x04\x00\x00\x00\x00\x00", 2);

    expect(runNotAnimatedRule('anim.gif', $bytes))
        ->toBe('Animated images aren’t supported. Use a static JPEG, PNG or WebP.');
});

test('it allows a static GIF', function (): void {
    // A single Graphic Control Extension block — one frame.
    $bytes = 'GIF89a'."\x21\xF9\x04\x00\x00\x00\x00\x00";

    expect(runNotAnimatedRule('static.gif', $bytes))->toBeNull();
});

test('it rejects an animated PNG (APNG)', function (): void {
    $bytes = "\x89PNG\r\n\x1a\n".'....acTL....IDAT....';

    expect(runNotAnimatedRule('anim.png', $bytes))
        ->toBe('Animated images aren’t supported. Use a static JPEG, PNG or WebP.');
});

test('it allows a static PNG', function (): void {
    $bytes = "\x89PNG\r\n\x1a\n".'....IHDR....IDAT....';

    expect(runNotAnimatedRule('static.png', $bytes))->toBeNull();
});

test('it allows a static PNG whose IDAT data merely contains the acTL bytes', function (): void {
    // "acTL" appearing after the first IDAT is payload, not an APNG marker.
    $bytes = "\x89PNG\r\n\x1a\n".'....IHDR....IDAT....acTL....';

    expect(runNotAnimatedRule('static.png', $bytes))->toBeNull();
});

test('it rejects an animated WebP', function (): void {
    // A VP8X extended header with the animation flag (bit 1) set.
    $bytes = 'RIFF'."\x00\x00\x00\x00".'WEBP'.'VP8X'."\x0a\x00\x00\x00"."\x02\x00\x00\x00\x00\x00";

    expect(runNotAnimatedRule('anim.webp', $bytes))
        ->toBe('Animated images aren’t supported. Use a static JPEG, PNG or WebP.');
});

test('it allows a static WebP', function (): void {
    $bytes = 'RIFF'."\x00\x00\x00\x00".'WEBP'.'VP8L'.'....';

    expect(runNotAnimatedRule('static.webp', $bytes))->toBeNull();
});

test('it allows an extended WebP with the animation flag clear', function (): void {
    // A VP8X header whose flags byte does not set the animation bit.
    $bytes = 'RIFF'."\x00\x00\x00\x00".'WEBP'.'VP8X'."\x0a\x00\x00\x00"."\x10\x00\x00\x00\x00\x00";

    expect(runNotAnimatedRule('static.webp', $bytes))->toBeNull();
});

test('it allows a JPEG even when its data happens to contain marker bytes', function (): void {
    // The format-gated checks must not fire on a JPEG that coincidentally holds
    // an "ANIM"/"acTL"/"VP8X" byte run in its scan data.
    $bytes = "\xFF\xD8\xFF\xE0".'JFIF....ANIM....acTL....VP8X....'."\xFF\xD9";

    expect(runNotAnimatedRule('photo.jpg', $bytes))->toBeNull();
});

test('it ignores non-file values', function (): void {
    $message = null;
    (new NotAnimatedImage)->validate('photo', 'not-a-file', function (string $error) use (&$message): void {
        $message = $error;
    });

    expect($message)->toBeNull();
});
