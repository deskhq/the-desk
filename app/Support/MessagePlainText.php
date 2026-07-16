<?php

declare(strict_types=1);

namespace App\Support;

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
     * A mention token: `@[Display Name](user-id)`, the id a 36-char UUID. Mirrors
     * the composer's token shape used by {@see \App\Actions\Channels\SyncMentions}.
     */
    private const string MENTION = '/@\[([^\]]+)\]\([0-9a-fA-F-]{36}\)/';

    /**
     * Unwrap mention tokens in the body to their display names.
     */
    public static function from(string $body): string
    {
        return preg_replace(self::MENTION, '@$1', $body) ?? $body;
    }
}
