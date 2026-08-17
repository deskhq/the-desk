<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\SecurityEventType;
use App\Events\SecurityEventOccurred;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\SessionRevokeRequest;
use App\Support\SessionRegistry;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Revoking a session is a mutation of the {@see SessionRegistry} rather than of
 * a domain model, so there is no Action for the security event to sit next to;
 * these two dispatch it themselves. Both facts also depend on the request's own
 * session, which only a controller has.
 */
final class SessionController extends Controller
{
    public function __construct(private readonly SessionRegistry $registry) {}

    /**
     * Revoke a single session, protecting the request's own session.
     *
     * A success toast is shown only when a session was actually revoked, so a
     * no-op revocation (an already-expired or unknown session) stays silent.
     */
    public function destroy(SessionRevokeRequest $request, string $session): RedirectResponse
    {
        if ($session !== $request->session()->getId()
            && $this->registry->forget($this->viewer($request)->id, $session)) {
            event(new SecurityEventOccurred($this->viewer($request), SecurityEventType::SessionRevoked));

            Inertia::flash('toast', ['type' => 'success', 'message' => __('Session revoked')]);
        }

        return back();
    }

    /**
     * Revoke every session for the user except the request's own session.
     *
     * A success toast is shown only when at least one other session was revoked.
     */
    public function destroyOthers(SessionRevokeRequest $request): RedirectResponse
    {
        $revoked = $this->registry->forgetOthers($this->viewer($request)->id, $request->session()->getId());

        if ($revoked > 0) {
            event(new SecurityEventOccurred($this->viewer($request), SecurityEventType::OtherSessionsRevoked));

            Inertia::flash('toast', ['type' => 'success', 'message' => __('Logged out of your other devices')]);
        }

        return back();
    }
}
