<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Csp\TheDeskPolicy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Spatie\Csp\Policy;
use Symfony\Component\HttpFoundation\Response;

/**
 * Emit the Content-Security-Policy on web responses.
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

        $header = config('csp.report_only')
            ? 'Content-Security-Policy-Report-Only'
            : 'Content-Security-Policy';

        $response->headers->set($header, Policy::create([TheDeskPolicy::class])->getContents());

        return $response;
    }
}
