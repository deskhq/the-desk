<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasskeysEnabled
{
    /**
     * Handle an incoming request.
     *
     * Passkey (WebAuthn) routes are registered unconditionally so Wayfinder keeps
     * emitting their route modules and the frontend build stays stable. Hiding the
     * UI is therefore not enough on its own: the Fortify passkey endpoints —
     * including the guest login ceremony — would otherwise stay callable directly.
     *
     * This enforces the deploy-time toggle server-side and, crucially, honours SSO
     * enforcement: under AUTH_SSO_ONLY the identity provider owns authentication,
     * and passkey *login* is an unauthenticated entry point, so leaving it open
     * would let a user bypass the mandatory provider. Every passkey route is
     * short-circuited to a 404 when passkeys are off or SSO is enforced.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $blocked = $request->routeIs('passkey.*')
            && (! config('fortify.passkeys_enabled') || config('sso.enforced'));

        abort_if($blocked, 404);

        return $next($request);
    }
}
