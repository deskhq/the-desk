<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

class ThrottlePasswordResetRequests extends ThrottleRequests
{
    /**
     * Apply the `password-reset` rate limiter to the password-reset POSTs.
     *
     * Fortify exposes limiter hooks for its login, two-factor, and passkey
     * routes but none for `password.email` / `password.update`, so this runs as
     * a global web middleware matched by route name — the same pattern
     * PreventDestructiveDemoActions uses to cover package-registered routes
     * without redefining them (a runtime route patch would not survive
     * `route:cache`, which production runs). Everything else passes through
     * untouched.
     *
     * @param  Closure(Request): (Response)  $next
     */
    #[\Override]
    public function handle($request, Closure $next, $maxAttempts = 60, $decayMinutes = 1, $prefix = ''): Response
    {
        if (! $request->routeIs('password.email', 'password.update')) {
            return $next($request);
        }

        return parent::handle($request, $next, 'password-reset');
    }
}
