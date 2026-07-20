<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Emit Strict-Transport-Security so a browser that has seen this host once
 * refuses to speak plain HTTP to it again, closing the SSL-strip window a
 * first or typed navigation would otherwise leave open.
 *
 * Global rather than web-only: the pin is a property of the transport, so the
 * REST API earns it on the same terms.
 */
final class AddStrictTransportSecurityHeader
{
    /**
     * The shortest max-age (one year, in seconds) the browsers' preload list
     * accepts a submission with.
     */
    private const int PRELOAD_MINIMUM_MAX_AGE = 31536000;

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Never on plain HTTP: a LAN deployment served over http:// would pin
        // its own hostname to a scheme it does not answer on, and there is no
        // way back other than waiting out the max-age on every browser.
        if (! config('security.hsts.enabled') || ! $request->isSecure()) {
            return $response;
        }

        $response->headers->set('Strict-Transport-Security', $this->policy());

        return $response;
    }

    private function policy(): string
    {
        // Floored at zero: a negative max-age is a typo the browser rejects,
        // and it would take the whole header down with it.
        $maxAge = max(0, (int) config('security.hsts.max_age'));
        $includeSubdomains = (bool) config('security.hsts.include_subdomains');

        $directives = ['max-age='.$maxAge];

        if ($includeSubdomains) {
            $directives[] = 'includeSubDomains';
        }

        // The preload list rejects anything under a year or excluding
        // subdomains, so advertising `preload` beside either states an intent
        // the policy cannot back. Send what is true instead.
        if (config('security.hsts.preload') && $includeSubdomains && $maxAge >= self::PRELOAD_MINIMUM_MAX_AGE) {
            $directives[] = 'preload';
        }

        return implode('; ', $directives);
    }
}
