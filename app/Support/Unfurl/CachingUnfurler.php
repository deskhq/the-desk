<?php

declare(strict_types=1);

namespace App\Support\Unfurl;

use Illuminate\Support\Facades\Cache;

/**
 * Remembers what the unfurl service decided, so the same link shared across many
 * messages is only fetched once.
 *
 * The distinction this class exists to draw is between the two ways an unfurl
 * can come back empty:
 *
 *  - **The service answered, and this link has no preview.** A fact about the
 *    link — it is dead, or blocked, or has no title — and one that will still be
 *    true in an hour. Remembered for a day, exactly as the code this replaced
 *    remembered its own failures, so a dead host is not re-dialled per message.
 *  - **The service could not be reached.** A fact about the *stack*. Caching it
 *    would let a five-minute outage decide that a day of perfectly good links
 *    are all broken, with `cache:clear` the only way back. So nothing is written
 *    and the next message carrying that link asks again.
 */
final readonly class CachingUnfurler implements Unfurler
{
    /**
     * Cached sentinel for a URL the service refused, so a failure is remembered
     * as a decision rather than as an absent key.
     */
    private const string FAILED = '__failed__';

    public function __construct(private Unfurler $inner) {}

    #[\Override]
    public function unfurl(array $urls): array
    {
        if ($urls === []) {
            return [];
        }

        $keys = [];

        foreach ($urls as $url) {
            $keys[$url] = 'link-preview:'.sha1($url);
        }

        /** @var array<string, mixed> $cached */
        $cached = Cache::many(array_values($keys));

        $resolved = [];
        $misses = [];

        foreach ($urls as $url) {
            $hit = $cached[$keys[$url]] ?? null;

            if ($hit === self::FAILED) {
                $resolved[$url] = null;

                continue;
            }

            if ($hit instanceof UnfurledPreview) {
                $resolved[$url] = $hit;

                continue;
            }

            $misses[] = $url;
        }

        if ($misses === []) {
            return $this->inOrder($urls, $resolved);
        }

        try {
            $fetched = $this->inner->unfurl($misses);
        } catch (UnfurlerUnavailable) {
            // Nothing is written. Every miss reports no preview for now, and the
            // next message carrying one of these links asks again.
            return $this->inOrder($urls, $resolved);
        }

        $ttl = now()->addSeconds((int) config('unfurl.cache_ttl'));

        foreach ($misses as $url) {
            $preview = $fetched[$url] ?? null;

            Cache::put($keys[$url], $preview ?? self::FAILED, $ttl);

            $resolved[$url] = $preview;
        }

        return $this->inOrder($urls, $resolved);
    }

    /**
     * One entry per URL asked about, in the order they were asked, whatever the
     * cache and the service between them managed to answer.
     *
     * @param  list<string>  $urls
     * @param  array<string, UnfurledPreview|null>  $resolved
     * @return array<string, UnfurledPreview|null>
     */
    private function inOrder(array $urls, array $resolved): array
    {
        $ordered = [];

        foreach ($urls as $url) {
            $ordered[$url] = $resolved[$url] ?? null;
        }

        return $ordered;
    }
}
