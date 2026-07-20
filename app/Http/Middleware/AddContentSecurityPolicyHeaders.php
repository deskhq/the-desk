<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Csp\FrameAncestors;
use App\Support\Csp\TheDeskPolicy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Spatie\Csp\Policy;
use Symfony\Component\HttpFoundation\Response;

/**
 * Emit the Content-Security-Policy on web responses, plus the X-Frame-Options
 * that stands in for its frame-ancestors directive on browsers without CSP
 * Level 2 support.
 * Both hang off `csp.enabled`: switching the app policy off means the operator
 * has taken ownership of these headers at their reverse proxy.
 *
 * This stands in for spatie/laravel-csp's own AddCspHeaders for two reasons: the
 * enforcing/report-only choice has to be made per request from config (so tests
 * and an operator's `php artisan config:cache` both see the current value rather
 * than whichever preset list the config file was written with), and the nonce
 * must be handed to Vite on the way *in*, before the Blade shell renders.
 */
final class AddContentSecurityPolicyHeaders
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // One nonce per request, shared by the Blade shell's inline appearance
        // script (@cspNonce) and every @vite tag. Two independent generators
        // would leave half the page unrunnable.
        Vite::useCspNonce(app('csp-nonce'));

        $response = $next($request);

        if (! config('csp.enabled')) {
            return $response;
        }

        if (config('csp.report_only')) {
            $response->headers->set(
                'Content-Security-Policy-Report-Only',
                Policy::create([TheDeskPolicy::class])->getContents(),
            );

            // No X-Frame-Options: it has no report-only form, so sending it
            // would enforce the framing rule the dry run is meant to only
            // observe.
            return $response;
        }

        $response->headers->set('Content-Security-Policy', Policy::create([TheDeskPolicy::class])->getContents());

        $frameOptions = FrameAncestors::frameOptions();

        if ($frameOptions !== null) {
            $response->headers->set('X-Frame-Options', $frameOptions);
        }

        return $response;
    }
}
