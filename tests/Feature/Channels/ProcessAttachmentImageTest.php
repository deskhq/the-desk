<?php

use App\Actions\Channels\ProcessAttachmentImage;
use App\Models\Attachment;
use App\Models\Channel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Store a JPEG carrying an EXIF profile on the fake disk and return a pending
 * attachment pointing at it.
 */
function storedImageWithExif(Channel $channel, int $width = 40, int $height = 30): Attachment
{
    $imagick = new Imagick;
    $imagick->newImage($width, $height, new ImagickPixel('red'));
    $imagick->setImageFormat('jpeg');
    $imagick->setImageProfile('exif', "Exif\0\0FAKE-GPS-METADATA");

    $path = "attachments/{$channel->id}/photo.jpg";
    Storage::disk('local')->put($path, $imagick->getImageBlob());

    return Attachment::factory()->for($channel)->create([
        'disk' => 'local',
        'path' => $path,
        'original_filename' => 'photo.jpg',
        'mime_type' => 'image/jpeg',
        'width' => $width,
        'height' => $height,
    ]);
}

/**
 * The names of the metadata profiles embedded in a stored image.
 *
 * @return list<string>
 */
function storedImageProfiles(string $path): array
{
    $imagick = new Imagick;
    $imagick->readImageBlob(Storage::disk('local')->get($path));

    return array_keys($imagick->getImageProfiles('*', true));
}

test('it strips exif metadata from the original and generates a thumbnail', function (): void {
    Storage::fake('local');
    $attachment = storedImageWithExif(Channel::factory()->create());

    expect(storedImageProfiles($attachment->path))->toContain('exif');

    app(ProcessAttachmentImage::class)->handle($attachment);

    $attachment->refresh();
    expect($attachment->thumb_path)->not->toBeNull();
    Storage::disk('local')->assertExists($attachment->thumb_path);
    expect(storedImageProfiles($attachment->path))->not->toContain('exif')
        ->and(storedImageProfiles($attachment->thumb_path))->not->toContain('exif');
});

test('it downscales the thumbnail to the configured maximum edge, keeping the original full-size', function (): void {
    Storage::fake('local');
    config()->set('attachments.thumbnail_max_px', 100);
    $attachment = storedImageWithExif(Channel::factory()->create(), 400, 200);

    app(ProcessAttachmentImage::class)->handle($attachment);
    $attachment->refresh();

    $thumb = new Imagick;
    $thumb->readImageBlob(Storage::disk('local')->get($attachment->thumb_path));
    expect($thumb->getImageWidth())->toBe(100)
        ->and($thumb->getImageHeight())->toBe(50)
        ->and($attachment->width)->toBe(400)
        ->and($attachment->height)->toBe(200);
});

test('it never upscales an image smaller than the thumbnail max', function (): void {
    Storage::fake('local');
    config()->set('attachments.thumbnail_max_px', 720);
    $attachment = storedImageWithExif(Channel::factory()->create(), 40, 30);

    app(ProcessAttachmentImage::class)->handle($attachment);

    $thumb = new Imagick;
    $thumb->readImageBlob(Storage::disk('local')->get($attachment->fresh()->thumb_path));
    expect($thumb->getImageWidth())->toBe(40)->and($thumb->getImageHeight())->toBe(30);
});

test('it strips metadata and thumbnails with the gd driver too', function (): void {
    Storage::fake('local');
    config()->set('attachments.image_driver', 'gd');
    $attachment = storedImageWithExif(Channel::factory()->create());

    app(ProcessAttachmentImage::class)->handle($attachment);

    $attachment->refresh();
    expect($attachment->thumb_path)->not->toBeNull()
        ->and(storedImageProfiles($attachment->path))->not->toContain('exif');
});

test('it leaves a non-image attachment untouched', function (): void {
    Storage::fake('local');
    $channel = Channel::factory()->create();
    $path = UploadedFile::fake()->create('report.pdf', 10, 'application/pdf')->store("attachments/{$channel->id}", 'local');
    $attachment = Attachment::factory()->for($channel)->document()->create(['disk' => 'local', 'path' => $path]);

    app(ProcessAttachmentImage::class)->handle($attachment);

    expect($attachment->fresh()->thumb_path)->toBeNull();
});

test('it leaves an svg attachment untouched', function (): void {
    Storage::fake('local');
    $channel = Channel::factory()->create();
    $path = UploadedFile::fake()->create('logo.svg', 4, 'image/svg+xml')->store("attachments/{$channel->id}", 'local');
    $attachment = Attachment::factory()->for($channel)->svg()->create(['disk' => 'local', 'path' => $path]);

    app(ProcessAttachmentImage::class)->handle($attachment);

    expect($attachment->fresh()->thumb_path)->toBeNull();
});

test('it leaves an undecodable image without a thumbnail rather than failing the upload', function (): void {
    Storage::fake('local');
    $channel = Channel::factory()->create();
    $path = "attachments/{$channel->id}/broken.png";
    Storage::disk('local')->put($path, 'this-is-not-a-real-png');
    $attachment = Attachment::factory()->for($channel)->create(['disk' => 'local', 'path' => $path, 'mime_type' => 'image/png']);

    app(ProcessAttachmentImage::class)->handle($attachment);

    expect($attachment->fresh()->thumb_path)->toBeNull()
        ->and(Storage::disk('local')->get($path))->toBe('this-is-not-a-real-png');
});
