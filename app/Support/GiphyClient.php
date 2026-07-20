<?php

declare(strict_types=1);

namespace App\Support;

use App\Actions\Channels\CreateGiphyAttachment;
use App\Data\GiphyGifData;
use App\Data\GiphySearchData;
use App\Models\Attachment;
use App\Support\Images\ImageProxy;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * A thin server-side proxy for the Giphy v2 API. The operator's API key never
 * leaves the server, results are short-TTL cached (respecting Giphy's caching
 * terms and protecting the shared key), and every response is normalized to the
 * small {@see GiphyGifData} shape the picker needs — the client never sees a raw
 * Giphy payload and never chooses which rendition is stored.
 */
class GiphyClient
{
    /**
     * Giphy's own per-request page-size ceiling (an API constraint, not an
     * operator knob).
     */
    private const int MAX_LIMIT = 50;

    /**
     * Whether the feature is configured. With no key the picker is fully hidden
     * and its endpoints 404, so nothing here is ever reached unconfigured.
     */
    public function isEnabled(): bool
    {
        return filled(config('services.giphy.key'));
    }

    /**
     * Fetch a page of GIFs: Giphy trending when the query is blank, else a search.
     * Results are cached per (query, offset, limit, lang, rating).
     */
    public function search(?string $query, int $offset = 0, int $limit = 24, string $lang = 'en'): GiphySearchData
    {
        $query = trim((string) $query);
        $limit = max(1, min($limit, self::MAX_LIMIT));
        $offset = max(0, $offset);

        $cacheKey = 'giphy:'.sha1(implode('|', [$query, $offset, $limit, $lang, $this->rating()]));

        /** @var array{results: array<int, array<string, mixed>>, nextOffset: int|null} $payload */
        $payload = Cache::remember(
            $cacheKey,
            now()->addSeconds((int) config('services.giphy.cache_ttl')),
            fn (): array => $this->fetchPage($query, $offset, $limit, $lang),
        );

        return new GiphySearchData(
            results: array_map($this->toData(...), $payload['results']),
            nextOffset: $payload['nextOffset'],
        );
    }

    /**
     * Re-resolve an opaque Giphy id to its authoritative media, or null when the
     * id is unknown/invalid. This is the sole authority on the stored URL: the
     * client sends only an id, never a URL, so a member cannot inject an arbitrary
     * host into another member's DOM.
     */
    public function resolve(string $id): ?GiphyGifData
    {
        try {
            $response = Http::timeout($this->timeout())->get($this->baseUrl().'/'.$id, [
                'api_key' => (string) config('services.giphy.key'),
            ]);
        } catch (ConnectionException) {
            // DNS/connect/timeout failure — degrade like an unknown id rather
            // than 500 the attach request.
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $gif = $response->json('data');

        if (! is_array($gif) || $gif === []) {
            return null;
        }

        $normalized = $this->normalize($gif);

        return $normalized === null ? null : $this->toData($normalized);
    }

    /**
     * Build the DTO from a normalized payload, routing the picker's grid preview
     * through the first-party image proxy so the browser never loads a tile from
     * Giphy's CDN (and `img-src` needs no wildcard). `url` deliberately stays the
     * raw CDN URL: it is what {@see CreateGiphyAttachment}
     * stores as the attachment's `remote_url`, and the proxying happens when that
     * attachment is serialised (see {@see Attachment::url()}) so a
     * stored row never carries a signature.
     *
     * @param  array<string, mixed>  $normalized
     */
    private function toData(array $normalized): GiphyGifData
    {
        $normalized['previewUrl'] = ImageProxy::url((string) $normalized['previewUrl']);

        return GiphyGifData::from($normalized);
    }

    /**
     * Perform a single search/trending request and normalize it into a cacheable
     * array payload. A failed request degrades to an empty page rather than
     * throwing, so a Giphy outage never 500s the picker.
     *
     * @return array{results: array<int, array<string, mixed>>, nextOffset: int|null}
     */
    private function fetchPage(string $query, int $offset, int $limit, string $lang): array
    {
        $endpoint = $query === '' ? $this->baseUrl().'/trending' : $this->baseUrl().'/search';

        $params = array_filter([
            'api_key' => (string) config('services.giphy.key'),
            'q' => $query === '' ? null : $query,
            'limit' => $limit,
            'offset' => $offset,
            'rating' => $this->rating(),
            // Trending is not query-scoped, so a language hint is meaningless there.
            'lang' => $query === '' ? null : $lang,
        ], fn (mixed $value): bool => $value !== null);

        try {
            $response = Http::timeout($this->timeout())->get($endpoint, $params);
        } catch (ConnectionException) {
            // DNS/connect/timeout failure degrades to an empty page, so a Giphy
            // outage never 500s the picker.
            return ['results' => [], 'nextOffset' => null];
        }

        if (! $response->successful()) {
            return ['results' => [], 'nextOffset' => null];
        }

        $results = [];

        foreach ((array) $response->json('data', []) as $gif) {
            $normalized = is_array($gif) ? $this->normalize($gif) : null;

            if ($normalized !== null) {
                $results[] = $normalized;
            }
        }

        return ['results' => $results, 'nextOffset' => $this->nextOffset($response->json('pagination', []), $offset, $limit, count($results))];
    }

    /**
     * Work out the offset for the next page from Giphy's pagination block. When
     * `total_count` is known we page until it is exhausted; trending omits it, so
     * a full page is taken to imply there is more.
     *
     * @param  array<string, mixed>  $pagination
     */
    private function nextOffset(array $pagination, int $offset, int $limit, int $resultCount): ?int
    {
        $count = (int) ($pagination['count'] ?? $resultCount);
        $totalCount = (int) ($pagination['total_count'] ?? 0);
        $currentOffset = (int) ($pagination['offset'] ?? $offset);
        $next = $currentOffset + $count;

        $hasMore = $count > 0 && ($totalCount > 0 ? $next < $totalCount : $count >= $limit);

        return $hasMore ? $next : null;
    }

    /**
     * Reduce a raw Giphy GIF object to the fields the picker and attachment need,
     * or null when it lacks a usable animated rendition or id.
     *
     * @param  array<string, mixed>  $gif
     * @return array{id: string, url: string, previewUrl: string, width: int, height: int, description: string|null}|null
     */
    private function normalize(array $gif): ?array
    {
        $id = (string) ($gif['id'] ?? '');
        $images = (array) ($gif['images'] ?? []);
        $media = $images['fixed_height'] ?? null;

        if ($id === '' || ! is_array($media) || empty($media['url'])) {
            return null;
        }

        $preview = $images['fixed_height_small'] ?? $images['preview_gif'] ?? $media;
        $preview = is_array($preview) ? $preview : $media;

        return [
            'id' => $id,
            'url' => (string) $media['url'],
            'previewUrl' => (string) ($preview['url'] ?? $media['url']),
            'width' => (int) ($media['width'] ?? 0),
            'height' => (int) ($media['height'] ?? 0),
            'description' => $this->description($gif),
        ];
    }

    /**
     * Pick the best alt text for a GIF: Giphy's `alt_text` if present, else its
     * `title`, else null.
     *
     * @param  array<string, mixed>  $gif
     */
    private function description(array $gif): ?string
    {
        foreach (['alt_text', 'title'] as $key) {
            $value = trim((string) ($gif[$key] ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * The Giphy v2 API base URL, from config.
     */
    private function baseUrl(): string
    {
        return (string) config('services.giphy.base_url');
    }

    /**
     * The per-request time budget in seconds, from config.
     */
    private function timeout(): int
    {
        return (int) config('services.giphy.timeout');
    }

    /**
     * The strictest content rating Giphy may return, from config; defaults to the
     * workplace-safe `g` if an operator blanks it.
     */
    private function rating(): string
    {
        $rating = (string) config('services.giphy.rating');

        return $rating === '' ? 'g' : $rating;
    }
}
