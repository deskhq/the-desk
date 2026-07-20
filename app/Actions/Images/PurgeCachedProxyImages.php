<?php

declare(strict_types=1);

namespace App\Actions\Images;

use App\Support\Images\FetchRemoteImage;
use Illuminate\Support\Facades\Storage;

class PurgeCachedProxyImages
{
    /**
     * Delete proxied image bytes that have outlived their cache TTL.
     *
     * The cache-store entry pointing at each file expires on its own, but the
     * file would otherwise sit on disk forever — an avatar or a link thumbnail
     * nobody has loaded in a week is pure dead weight. A file that is still
     * wanted is simply refetched on the next request.
     *
     * @return int the number of cached images deleted
     */
    public function handle(): int
    {
        $disk = Storage::disk(FetchRemoteImage::DISK);
        $cutoff = now()->subSeconds(FetchRemoteImage::CACHE_TTL_SECONDS)->getTimestamp();

        $purged = 0;

        foreach ($disk->files(FetchRemoteImage::DIRECTORY) as $file) {
            if ($disk->lastModified($file) >= $cutoff) {
                continue;
            }

            $disk->delete($file);

            $purged++;
        }

        return $purged;
    }
}
