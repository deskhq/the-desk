<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordLoginEnabled
{
    /**
     * Handle an incoming request.
     *
     * When SSO enforcement is active (AUTH_SSO_ONLY=true *and* a provider is
     * configured) all access flows through the directory, so the Fortify
     * password-login attempt is blocked. The login *view* stays reachable — it
     * still renders the "Sign in with SSO" entry point — so only the credential
     * POST (`login.store`) is short-circuited.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(config('sso.enforced') && $request->routeIs('login.store'), 404);

        return $next($request);
    }
}
