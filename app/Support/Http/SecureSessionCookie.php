<?php

declare(strict_types=1);

namespace App\Support\Http;

/**
 * Whether the session cookie should carry the `Secure` flag when the operator
 * has not said either way.
 *
 * Laravel ships `SESSION_SECURE_COOKIE` with no default, which resolves to
 * `false` — so an HTTPS install would hand out session cookies a browser is
 * happy to replay over plain HTTP unless the operator knew to set the key. The
 * app's own APP_URL already states the scheme it is served on, so read the
 * answer from there instead of asking for it twice.
 */
final class SecureSessionCookie
{
    /**
     * Read from config/session.php, so it takes the raw APP_URL rather than
     * touching the container — config is loaded before anything is bound.
     */
    public static function defaultFor(mixed $appUrl): bool
    {
        return is_string($appUrl)
            && str_starts_with(mb_strtolower(trim($appUrl)), 'https://');
    }
}
