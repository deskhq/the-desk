<?php

declare(strict_types=1);

namespace App\Support\Images;

use App\Actions\Images\PurgeCachedProxyImages;
use App\Support\Http\FetchedBody;
use App\Support\Http\FetchPolicy;
use App\Support\Http\GuardedEgress;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Fetches a remote image once and keeps the bytes on a local disk, so the app
 * can serve every image from its own origin (see {@see ImageProxy}).
 *
 * The URL comes from a member — a scraped `og:image`, a Giphy rendition, the
 * operator's Gravatar base — so the fetch goes through {@see GuardedEgress},
 * which owns the SSRF guarding, the pinning and the redirect walk. This class
 * supplies only what is its own: which image types are worth proxying, five
 * megabytes, and where the bytes land.
 *
 * Every failure path returns null rather than throwing. An instance with no
 * egress therefore degrades to a 404 per image (initials avatar, no link
 * thumbnail) instead of hanging or 500ing, and the negative result is cached
 * briefly so a dead host is not re-dialled on every page render.
 */
final readonly class FetchRemoteImage
{
    /**
     * The disk cached image bytes live on. Private, since the proxy route — not
     * a public URL — is what serves them.
     */
    public const string DISK = 'local';

    /**
     * The directory holding cached image bytes, swept by
     * {@see PurgeCachedProxyImages}.
     */
    public const string DIRECTORY = 'image-proxy';

    /**
     * How long a fetched image stays cached, on disk and in the cache store.
     */
    public const int CACHE_TTL_SECONDS = 604800; // 7 days

    /**
     * How long a failed fetch is remembered. Far shorter than a success: a
     * timeout is usually transient, and re-dialling a dead host on every render
     * is what the negative cache exists to prevent.
     */
    private const int FAILURE_TTL_SECONDS = 600; // 10 minutes

    /**
     * Hard cap on the bytes read from a remote image. Unlike an unfurl, this one
     * refuses an oversize body rather than trimming it: half an image is a
     * corrupt image, not a small one.
     */
    private const int MAX_BYTES = 5242880; // 5 MB

    /**
     * The image types worth proxying. SVG is deliberately absent: it is a
     * document that can carry script, and serving one from our own origin is
     * exactly what the tightened `img-src` is meant to prevent.
     *
     * @var list<string>
     */
    private const array ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'];

    /**
     * Cached sentinel for a URL that could not be fetched.
     */
    private const string FAILED = '__failed__';

    public function __construct(private GuardedEgress $egress) {}

    /**
     * Resolve a remote image URL to its cached bytes, fetching it on first use.
     *
     * Concurrent first requests for the same URL may each fetch it; the write is
     * idempotent (same URL, same path, same bytes), so a lock would buy nothing
     * but a blocked web worker.
     *
     * @return array{path: string, mime: string}|null null when the URL cannot be fetched
     */
    public function handle(string $url): ?array
    {
        $key = 'image-proxy:'.hash('sha256', $url);
        $cached = Cache::get($key);

        if ($cached === self::FAILED) {
            return null;
        }

        if (is_array($cached) && Storage::disk(self::DISK)->exists($cached['path'])) {
            /** @var array{path: string, mime: string} $cached */
            return $cached;
        }

        $stored = $this->fetch($url);

        Cache::put(
            $key,
            $stored ?? self::FAILED,
            now()->addSeconds($stored === null ? self::FAILURE_TTL_SECONDS : self::CACHE_TTL_SECONDS),
        );

        return $stored;
    }

    /**
     * Fetch the URL through the guarded module and store what comes back.
     *
     * The cache key and the file name are both hashes of the URL the caller
     * asked for, not of whichever URL the redirect walk ended on, so the same
     * request resolves to the same bytes however the remote host moves them.
     *
     * @return array{path: string, mime: string}|null
     */
    private function fetch(string $url): ?array
    {
        $fetched = $this->egress->fetch($url, FetchPolicy::refusingOver(
            self::MAX_BYTES,
            static fn (string $contentType): bool => in_array($contentType, self::ALLOWED_MIMES, true),
        ));

        if (! $fetched instanceof FetchedBody) {
            return null;
        }

        $path = self::DIRECTORY.'/'.hash('sha256', $url);

        Storage::disk(self::DISK)->put($path, $fetched->body);

        return ['path' => $path, 'mime' => $fetched->contentType];
    }
}
