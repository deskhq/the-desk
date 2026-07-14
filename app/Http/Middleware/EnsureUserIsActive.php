<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces directory deprovisioning on every authenticated web request.
 *
 * Deactivating an account (SCIM push) revokes its sessions immediately, but that
 * alone would not stop the user simply signing in again through OIDC/LDAP. This
 * middleware fail-closes that gap: a deactivated user is logged out and bounced
 * to the login screen on any request, so a tombstoned account stays locked out
 * regardless of how it authenticated — until a subsequent `active: true` clears
 * the flag (see App\Actions\Sso\SetSsoUserActivation).
 */
class EnsureUserIsActive
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->isDeactivated()) {
            Auth::guard()->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return redirect()->guest(route('login'))->with(
                'status',
                __('Your account has been deactivated. Please contact your administrator.'),
            );
        }

        return $next($request);
    }
}
