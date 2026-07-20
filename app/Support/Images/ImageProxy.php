<?php

declare(strict_types=1);

namespace App\Support\Images;

use App\Support\Csp\TheDeskPolicy;
use Illuminate\Support\Facades\URL;

/**
 * Rewrites a remote image URL into a first-party one served by the app.
 *
 * Every image the browser loads then comes from our own origin, which is what
 * lets `img-src` drop the `https:` wildcard (see
 * {@see TheDeskPolicy}) and stops a reader's IP, user agent and
 * referring page leaking to Giphy, Gravatar and whatever site someone linked.
 *
 * The URL is signed, and the signature is what keeps the route from being an
 * open proxy: only a URL the server itself emitted resolves, so an authenticated
 * member cannot hand us an arbitrary target to fetch. It carries no expiry on
 * purpose — a proxied URL is embedded in Inertia props and in the browser's
 * image cache, and an expiring signature would break images on a page left open.
 * Rotating `APP_KEY` invalidates every one of them, which is the intended
 * revocation path.
 */
class ImageProxy
{
    /**
     * Sign a remote image URL for the proxy route, or null when there is nothing
     * to proxy — so a caller can pass a nullable URL straight through and keep
     * its own "no image" fallback (initials avatar, no link thumbnail).
     */
    public static function url(?string $remoteUrl): ?string
    {
        if ($remoteUrl === null || trim($remoteUrl) === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $remoteUrl) !== 1) {
            return null;
        }

        return URL::signedRoute('images.proxy', ['url' => $remoteUrl], absolute: false);
    }
}
