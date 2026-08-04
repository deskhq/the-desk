<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One reading of "what is waiting here": ordinary unread traffic and unread
 * mentions, for a single channel or for a whole workspace.
 *
 * Both numbers arrive already suppressed — a muted conversation, or one the
 * viewer set to "nothing", reports nothing at all — so the client renders the
 * badge it is given rather than re-deciding whether it is allowed to.
 */
#[TypeScript]
class UnreadCountsData extends Data
{
    public function __construct(
        /** Ordinary unread messages, thread-only replies excluded. */
        public int $unread,
        /** Unread messages that named the viewer, wherever they landed. */
        public int $mention,
    ) {}
}
