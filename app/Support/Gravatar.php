<?php

declare(strict_types=1);

namespace App\Support;

class Gravatar
{
    /**
     * Derive the Gravatar avatar URL for an email address.
     *
     * The email is lowercased and trimmed then MD5-hashed (Gravatar's keying
     * scheme), so the raw address never appears in the URL. Returns null when
     * avatars are disabled, letting every consumer fall back to the initials
     * avatar without an outbound request to gravatar.com.
     *
     * Pass `$default` to override the configured `d=` fallback — e.g. the demo
     * seeder requests generated `identicon` avatars so every fixture user has a
     * distinct picture rather than the production `404`/initials behaviour.
     */
    public static function url(string $email, ?string $default = null): ?string
    {
        if (! config('gravatar.enabled')) {
            return null;
        }

        $hash = md5(strtolower(trim($email)));

        return sprintf(
            '%s/%s?d=%s&s=%d',
            rtrim((string) config('gravatar.base_url'), '/'),
            $hash,
            rawurlencode($default ?? (string) config('gravatar.default')),
            (int) config('gravatar.size'),
        );
    }
}
