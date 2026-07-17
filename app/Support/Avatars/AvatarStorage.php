<?php

declare(strict_types=1);

namespace App\Support\Avatars;

use App\Support\Images\ImageProcessor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Encoders\AutoEncoder;

class AvatarStorage
{
    /**
     * The longest edge (px) the stored avatar is scaled down to fit. Only ever
     * scales down; a square source keeps its aspect and circles center-crop it.
     */
    public const int MAX_PX = 512;

    /**
     * The longest edge (px) of the generated thumbnail, sized for the small
     * roster / DM-list avatar surfaces.
     */
    public const int THUMBNAIL_PX = 96;

    /**
     * The public, browser-cacheable disk avatars live on — unlike message
     * attachments (private, auth-served), an avatar is served by direct URL.
     */
    public const string DISK = 'public';

    public function __construct(private readonly ImageProcessor $images) {}

    /**
     * Strip metadata, downscale, and store an uploaded avatar plus its thumbnail
     * under an unguessable filename, returning the public URL and storage path.
     *
     * @return array{url: string, path: string}
     */
    public function store(UploadedFile $file): array
    {
        $image = $this->images->decode((string) file_get_contents($file->getRealPath()));

        $this->images->stripMetadata($image);

        $image->scaleDown(self::MAX_PX, self::MAX_PX);
        $full = (string) $image->encode(new AutoEncoder(quality: 90));

        // Scale the same (already stripped, already downscaled) image again for
        // the thumbnail so the source is decoded and stripped only once.
        $image->scaleDown(self::THUMBNAIL_PX, self::THUMBNAIL_PX);
        $thumbnail = (string) $image->encode(new AutoEncoder(quality: 80));

        $path = 'avatars/'.Str::uuid()->toString().'.'.$file->guessExtension();

        $disk = Storage::disk(self::DISK);
        $disk->put($path, $full);
        $disk->put($this->thumbnailPath($path), $thumbnail);

        return ['url' => (string) $disk->url($path), 'path' => $path];
    }

    /**
     * Delete a stored avatar blob and its thumbnail. A null path (the avatar was
     * derived, never uploaded) is a no-op, so replace and remove can call this
     * unconditionally with the previous path.
     */
    public function delete(?string $path): void
    {
        if ($path === null) {
            return;
        }

        Storage::disk(self::DISK)->delete([$path, $this->thumbnailPath($path)]);
    }

    /**
     * The thumbnail's path: a `thumbnails/` sibling keeping the original
     * basename (and extension, so it serves with the same content type).
     */
    private function thumbnailPath(string $path): string
    {
        return 'avatars/thumbnails/'.basename($path);
    }
}
