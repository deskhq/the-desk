<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\SessionRevokeRequest;
use App\Support\SessionRegistry;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class SessionController extends Controller
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
            && $this->registry->forget($request->user()->id, $session)) {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Session revoked.')]);
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
        $revoked = $this->registry->forgetOthers($request->user()->id, $request->session()->getId());

        if ($revoked > 0) {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Logged out of your other devices.')]);
        }

        return back();
    }
}
