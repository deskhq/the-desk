<?php

declare(strict_types=1);

namespace App\Support\Unfurl;

/**
 * Turns the URLs in a message into their link previews.
 *
 * Takes the whole batch rather than one URL at a time, because the service
 * behind it fetches them concurrently: a message linking three slow hosts costs
 * the longest of them, not the sum. The PHP this replaced walked them in a loop
 * and could not.
 *
 * Implemented twice, and composed: {@see HttpUnfurler} talks to the service and
 * {@see CachingUnfurler} wraps it so the same link shared across many messages is
 * only fetched once. The container binds the pair; nothing else should assemble
 * them.
 */
interface Unfurler
{
    /**
     * Unfurl every URL, returning one entry per input keyed by URL.
     *
     * A null means no preview, for every reason a URL can fail to have one — a
     * blocked destination, a dead host, a page with no title. The caller shows
     * the same empty result for all of them.
     *
     * @param  list<string>  $urls
     * @return array<string, UnfurledPreview|null>
     *
     * @throws UnfurlerUnavailable when the service itself could not be reached,
     *                             which is a fact about the stack rather than
     *                             about any of these links
     */
    public function unfurl(array $urls): array;
}
