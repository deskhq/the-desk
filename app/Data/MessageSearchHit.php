<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\Message;

/**
 * One search match: the hydrated {@see Message} paired with its highlighted
 * snippet. The snippet is keyed to the message by construction, so it survives
 * the authoritative Eloquent re-query the search action runs after the engine
 * selects the candidate set. {@see MessageSearchResultData::fromHit()} shapes it
 * for the client.
 */
final readonly class MessageSearchHit
{
    public function __construct(
        public Message $message,
        public string $snippet,
    ) {}
}
