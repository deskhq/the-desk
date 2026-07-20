<?php

declare(strict_types=1);

namespace App\Support\Http;

class AbsoluteUrl
{
    /**
     * Resolve a possibly-relative URL against a base: absolute URLs pass through,
     * protocol-relative URLs inherit the base scheme, and everything else is
     * hung off the base origin.
     *
     * Redirect `Location` headers and Open Graph `og:image` values are both
     * routinely relative, and both feed a URL straight back into the SSRF guard,
     * so the two callers resolve them the same way.
     */
    public static function from(string $baseUrl, string $url): string
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
