<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;

class FetchLinkPreview
{
    /**
     * Total time budget for a single request, in seconds.
     */
    private const int TIMEOUT_SECONDS = 5;

    /**
     * How many redirect hops to follow before giving up. Each hop is re-validated
     * against the SSRF guard so a public URL can't bounce us onto an internal one.
     */
    private const int MAX_REDIRECTS = 3;

    /**
     * Hard cap on the HTML we read and parse, guarding against huge responses.
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

    public function __construct(private HostResolver $resolver) {}

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
            fn () => $this->unfurl($url) ?? self::FAILED,
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
        $fetched = $this->fetch($url);

        if ($fetched === null) {
            return null;
        }

        [$finalUrl, $html] = $fetched;

        return $this->parse($html, $finalUrl);
    }

    /**
     * Request the URL, manually following redirects and re-validating each hop.
     *
     * @return array{0: string, 1: string}|null The final URL and its HTML body.
     */
    private function fetch(string $url): ?array
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            if (! $this->isSafe($url)) {
                return null;
            }

            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withOptions(['allow_redirects' => false])
                ->get($url);

            if ($response->redirect()) {
                $location = (string) $response->header('Location');

                if ($location === '') {
                    return null;
                }

                $url = $this->absolutize($url, $location);

                continue;
            }

            if (! $response->successful()) {
                return null;
            }

            if (! str_contains((string) $response->header('Content-Type'), 'text/html')) {
                return null;
            }

            if ((int) $response->header('Content-Length') > self::MAX_BYTES) {
                return null;
            }

            return [$url, substr($response->body(), 0, self::MAX_BYTES)];
        }

        return null;
    }

    /**
     * Decide whether a URL is safe to fetch: an http(s) URL whose host resolves
     * only to public IPs. Any private, loopback, link-local (incl. the cloud
     * metadata endpoint) or reserved address rejects the whole URL.
     */
    private function isSafe(string $url): bool
    {
        $parts = parse_url($url);

        if ($parts === false || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)) {
            return false;
        }

        $host = $parts['host'] ?? '';
        $ips = $host === '' ? [] : $this->resolver->resolve($host);

        if ($ips === []) {
            return false;
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }

        return true;
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
            'image' => $image === null ? null : $this->absolutize($baseUrl, $image),
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

    /**
     * Resolve a possibly-relative URL against a base: absolute URLs pass through,
     * protocol-relative URLs inherit the base scheme, and everything else is
     * hung off the base origin.
     */
    private function absolutize(string $baseUrl, string $url): string
    {
        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        $base = parse_url($baseUrl);
        $scheme = $base['scheme'] ?? 'https';

        if (str_starts_with($url, '//')) {
            return $scheme.':'.$url;
        }

        return $scheme.'://'.($base['host'] ?? '').'/'.ltrim($url, '/');
    }
}
