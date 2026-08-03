<?php

declare(strict_types=1);

namespace App\Support\Http;

/**
 * What {@see GuardedEgress::fetch()} hands back once a destination has survived
 * every hop: the URL the bytes actually came from, the content type the policy
 * accepted, and the body itself, already within the policy's cap.
 */
final readonly class FetchedBody
{
    /**
     * @param  string  $url  The final URL, after any redirects the guard cleared — what a relative link in the body resolves against.
     * @param  string  $contentType  Lower-cased, parameters stripped (`text/html`, never `text/html; charset=utf-8`).
     */
    public function __construct(
        public string $url,
        public string $contentType,
        public string $body,
    ) {}
}
