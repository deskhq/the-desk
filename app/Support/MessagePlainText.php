<?php

declare(strict_types=1);

namespace App\Support;

use App\Actions\Channels\SyncMentions;

/**
 * Reduces a stored message body to the plain text a reader sees, for search
 * indexing and snippet extraction. Today it unwraps mention tokens — the
 * composer stores `@[Display Name](user-id)` — to a bare `@Display Name`, so a
 * search matches (and a snippet renders) the readable name rather than the raw
 * token and its id.
 */
final class MessagePlainText
{
    /**
     * A mention token: `@[Display Name](user-id)` for a member, or
     * `@[handle](group:group-id)` for a user group, the id a 36-char UUID.
     * Mirrors the composer's token shape used by {@see SyncMentions}; both
     * unwrap to the readable label a reader sees.
     */
    private const string MENTION = '/@\[([^\]]+)\]\((?:group:)?[0-9a-fA-F-]{36}\)/';

    /**
     * Unwrap mention tokens in the body to their display names.
     */
    public static function from(string $body): string
    {
        return preg_replace(self::MENTION, '@$1', $body) ?? $body;
    }
}
