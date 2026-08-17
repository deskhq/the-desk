<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\Http\AbsoluteUrl;
use App\Support\Http\FetchedBody;
use App\Support\Http\FetchPolicy;
use App\Support\Http\GuardedEgress;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Unfurls a member-posted URL into its Open Graph preview.
 *
 * The URL comes from whatever a member typed into a message, so the fetch goes
 * through {@see GuardedEgress}, which owns the SSRF guarding, the pinning and
 * the redirect walk. This class supplies only what is its own: HTML, two
 * megabytes of it, and what to make of what comes back.
 */
class FetchLinkPreview
{
    /**
     * Hard cap on the HTML we read and parse, guarding against huge responses.
     * A truncated document is fine here — everything an unfurl reads is in
     * `<head>`.
     */
    private const int MAX_BYTES = 2097152; // 2 MB

    /**
     * How long a resolved (or failed) unfurl stays cached per URL.
     */
    private const int CACHE_TTL_SECONDS = 86400; // 24 hours

    /**
     * Cached sentinel for a URL that could not be unfurled, so a blocked or dead
     * link is never refetched within the TTL.
     */
    private const string FAILED = '__failed__';

    public function __construct(private readonly GuardedEgress $egress) {}

    /**
     * Unfurl a URL into its Open Graph preview, or null when it can't be fetched.
     *
     * The result (success or failure) is cached by URL so the same link shared
     * across many messages is only fetched once within the TTL.
     *
     * @return array{title: string, description: string|null, image: string|null, siteName: string|null}|null
     */
    public function handle(string $url): ?array
    {
        $result = Cache::remember(
            'link-preview:'.sha1($url),
            now()->addSeconds(self::CACHE_TTL_SECONDS),
            fn (): array|string => $this->unfurl($url) ?? self::FAILED,
        );

        return $result === self::FAILED ? null : $result;
    }

    /**
     * Fetch the URL (following safe redirects) and parse its metadata.
     *
     * @return array{title: string, description: string|null, image: string|null, siteName: string|null}|null
     */
    private function unfurl(string $url): ?array
    {
        $fetched = $this->egress->fetch($url, FetchPolicy::truncatingAt(
            self::MAX_BYTES,
            static fn (string $contentType): bool => $contentType === 'text/html',
        ));

        if (! $fetched instanceof FetchedBody) {
            return null;
        }

        return $this->parse($fetched->body, $fetched->url);
    }

    /**
     * Extract the preview fields from HTML, preferring Open Graph tags and
     * falling back to the `<title>` and host. Returns null when there's nothing
     * worth showing (no title at all).
     *
     * @return array{title: string, description: string|null, image: string|null, siteName: string|null}|null
     */
    private function parse(string $html, string $baseUrl): ?array
    {
        if (trim($html) === '') {
            return null;
        }

        $crawler = new Crawler($html);

        $title = $this->metaContent($crawler, 'og:title') ?? $this->titleTag($crawler);

        if ($title === null) {
            return null;
        }

        $image = $this->metaContent($crawler, 'og:image');

        return [
            'title' => $title,
            'description' => $this->metaContent($crawler, 'og:description'),
            'image' => $image === null ? null : AbsoluteUrl::from($baseUrl, $image),
            'siteName' => $this->metaContent($crawler, 'og:site_name') ?? (parse_url($baseUrl, PHP_URL_HOST) ?: null),
        ];
    }

    /**
     * Read the trimmed `content` of the first matching `<meta>` tag (by either
     * `property` or `name`), or null when absent or empty.
     */
    private function metaContent(Crawler $crawler, string $key): ?string
    {
        $node = $crawler->filter('meta[property="'.$key.'"], meta[name="'.$key.'"]')->first();

        if ($node->count() === 0) {
            return null;
        }

        $content = trim((string) $node->attr('content'));

        return $content === '' ? null : $content;
    }

    /**
     * Read the trimmed text of the document's `<title>`, or null when absent/empty.
     */
    private function titleTag(Crawler $crawler): ?string
    {
        $node = $crawler->filter('title')->first();

        if ($node->count() === 0) {
            return null;
        }

        $text = trim($node->text());

        return $text === '' ? null : $text;
    }
}
