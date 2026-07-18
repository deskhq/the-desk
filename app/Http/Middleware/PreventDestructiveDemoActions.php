<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class PreventDestructiveDemoActions
{
    /**
     * Owner-level destructive routes blocked while DEMO_MODE is on.
     *
     * The public demo signs every visitor in as the same workspace owner, so any
     * of these would let one visitor lock out, evict, or deface the workspace for
     * everyone else until the hourly reset. They fall into three buckets:
     *
     *  - Account/identity: changing the shared account's email, password, name
     *    (`profile.update`), enabling 2FA or a passkey, or revoking sessions —
     *    each locks the *next* visitor out of the shared credentials.
     *  - Workspace destruction: deleting the account or team, transferring
     *    ownership away, removing a member, or leaving the team.
     *  - Identity-of-record: renaming the team or editing its slug
     *    (`teams.update`) — the reset wipe matches on the `northwind-labs` slug,
     *    so a slug change silently defeats the reset.
     *
     * Matched by route name so the guard covers the package-registered Fortify /
     * Passkey routes too, without touching their route definitions. This is the
     * server-side gate; the UI additionally renders these controls disabled, but
     * that is convenience only — this block is the source of truth.
     *
     * @var list<string>
     */
    private const array BLOCKED_ROUTES = [
        'profile.update',
        'profile.destroy',
        'user-password.update',
        'sessions.destroy',
        'sessions.destroy-others',
        'two-factor.enable',
        'two-factor.confirm',
        'passkey.store',
        'passkey.confirm',
        'teams.update',
        'teams.destroy',
        'teams.leave',
        'teams.members.transfer-ownership',
        'teams.members.destroy',
    ];

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('demo.mode') || ! $request->routeIs(...self::BLOCKED_ROUTES)) {
            return $next($request);
        }

        $message = __('This action is disabled in the demo.');

        abort_if($request->expectsJson(), 403, $message);

        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return back();
    }
}
