<?php

namespace App\Data;

use App\Models\User;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A user's live custom status, as every surface that renders one reads it.
 *
 * Only ever built for a status that is actually showing: {@see forUser()}
 * returns null for a user who has set none *and* for one whose expiry has
 * already passed, so a lapsed status disappears the moment it lapses rather
 * than waiting for the scheduled sweep to null the columns.
 */
#[TypeScript]
class UserStatusData extends Data
{
    public function __construct(
        /** The picker value: a native emoji glyph, or a `:name:` custom-emoji shortcode. */
        public string $emoji,
        public ?string $text,
        /** ISO-8601 instant the status clears, or null when it never does. */
        public ?string $expiresAt,
    ) {}

    /**
     * Build the DTO for a user's currently-showing status, or null when they
     * have none — never set, or set with an expiry that has already passed.
     */
    public static function forUser(User $user): ?self
    {
        if (! $user->hasLiveStatus()) {
            return null;
        }

        return new self(
            emoji: (string) $user->status_emoji,
            text: $user->status_text,
            expiresAt: $user->status_expires_at?->toIso8601String(),
        );
    }
}
