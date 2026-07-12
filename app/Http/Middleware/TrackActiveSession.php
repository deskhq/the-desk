<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\SessionRegistry;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the per-user {@see SessionRegistry} index in step with real requests and
 * enforces revocation.
 *
 * On every authenticated request it records the current session (registering the
 * device the first time it is seen and refreshing its activity thereafter). When
 * a session's own id is present in the index it is left signed in; when that id
 * has been removed from another device — i.e. revoked — the request is logged out
 * and bounced to the login screen, so a revoked session can no longer make
 * authenticated requests regardless of the configured session driver.
 */
class TrackActiveSession
{
    /**
     * The session key recording the id this session was last indexed under.
     */
    private const string MARKER = 'active_session_id';

    public function __construct(private readonly SessionRegistry $registry) {}

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $request->hasSession()) {
            return $next($request);
        }

        $session = $request->session();
        $sessionId = $session->getId();
        $userId = (string) $user->getAuthIdentifier();
        $registeredId = $session->get(self::MARKER);

        if ($registeredId !== $sessionId) {
            // A fresh login, or the session id was regenerated: move any prior
            // index entry onto the current id and register this device.
            if (is_string($registeredId)) {
                $this->registry->forget($userId, $registeredId);
            }

            $this->track($request, $userId, $sessionId);
            $session->put(self::MARKER, $sessionId);

            return $next($request);
        }

        if (! $this->registry->has($userId, $sessionId)) {
            // This session was revoked from another device.
            Auth::guard()->logout();
            $session->invalidate();
            $session->regenerateToken();

            return redirect()->guest(route('login'));
        }

        $this->track($request, $userId, $sessionId);

        return $next($request);
    }

    /**
     * Record the current session's activity in the index.
     */
    private function track(Request $request, string $userId, string $sessionId): void
    {
        $this->registry->record($userId, $sessionId, $request->ip(), $request->userAgent());
    }
}
