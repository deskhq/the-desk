<?php

declare(strict_types=1);

namespace App\Support\Images;

use App\Actions\Images\PurgeCachedProxyImages;
use App\Support\Http\AbsoluteUrl;
use App\Support\Http\OutboundUrlGuard;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Fetches a remote image once and keeps the bytes on a local disk, so the app
 * can serve every image from its own origin (see {@see ImageProxy}).
 *
 * The URL comes from a member — a scraped `og:image`, a Giphy rendition, the
 * operator's Gravatar base — so every hop goes through {@see OutboundUrlGuard}:
 * public http(s) hosts only, connection pinned to the vetted IP, redirects
 * followed manually so each new target is re-checked rather than handed to curl.
 *
 * Every failure path returns null rather than throwing. An instance with no
 * egress therefore degrades to a 404 per image (initials avatar, no link
 * thumbnail) instead of hanging or 500ing, and the negative result is cached
 * briefly so a dead host is not re-dialled on every page render.
 */
class FetchRemoteImage
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
     * Total time budget for a single hop, in seconds.
     */
    private const int TIMEOUT_SECONDS = 5;

    /**
     * How many redirect hops to follow before giving up. Each hop is re-validated
     * against the SSRF guard so a public URL can't bounce us onto an internal one.
     */
    private const int MAX_REDIRECTS = 3;

    /**
     * Hard cap on the bytes read from a remote image.
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

    public function __construct(private readonly OutboundUrlGuard $guard) {}

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
     * Request the URL, manually following redirects, and store the bytes.
     *
     * @return array{path: string, mime: string}|null
     */
    private function fetch(string $url): ?array
    {
        $target = $url;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            if (! OutboundUrlGuard::isPublic($target)) {
                return null;
            }

            $pinnedIp = $this->guard->resolveDeliveryIp($target);

            if ($pinnedIp === false) {
                return null;
            }

            try {
                $response = Http::timeout(self::TIMEOUT_SECONDS)
                    ->withOptions($this->guard->transportOptions($target, $pinnedIp))
                    ->get($target);
            } catch (Throwable) {
                // DNS/connect/timeout failure — degrade to no image rather than
                // surfacing a 500 on an air-gapped instance.
                return null;
            }

            if ($response->redirect()) {
                $location = (string) $response->header('Location');

                if ($location === '') {
                    return null;
                }

                $target = AbsoluteUrl::from($target, $location);

                continue;
            }

            if (! $response->successful()) {
                return null;
            }

            $mime = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));

            if (! in_array($mime, self::ALLOWED_MIMES, true)) {
                return null;
            }

            if ((int) $response->header('Content-Length') > self::MAX_BYTES) {
                return null;
            }

            $body = $response->body();

            if ($body === '' || strlen($body) > self::MAX_BYTES) {
                return null;
            }

            $path = self::DIRECTORY.'/'.hash('sha256', $url);

            Storage::disk(self::DISK)->put($path, $body);

            return ['path' => $path, 'mime' => $mime];
        }

        return null;
    }
}
