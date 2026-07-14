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
     * LDAP is the exception: its bind auth runs *through* this same login POST,
     * so blanket-blocking it would block directory sign-in. When LDAP is enabled
     * the POST is allowed through and the custom Fortify authenticateUsing
     * callback enforces the split — directory bind allowed, local password
     * rejected. So the POST is only short-circuited here for OIDC-only
     * enforcement, where nothing legitimate posts to it.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $blocked = config('sso.enforced')
            && ! config('sso.ldap.enabled')
            && $request->routeIs('login.store');

        abort_if($blocked, 404);

        return $next($request);
    }
}
