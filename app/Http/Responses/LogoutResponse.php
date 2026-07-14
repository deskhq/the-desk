<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;
use Symfony\Component\HttpFoundation\Response;

class LogoutResponse implements LogoutResponseContract
{
    /**
     * Send the client to the login page with a full-page navigation rather than
     * an Inertia SPA swap.
     *
     * Logging out nulls the shared `auth.user` prop, and the authenticated
     * components still mounted during a client-side swap re-evaluate their
     * `auth.user`-derived computeds against null and throw, aborting the swap:
     * the URL changes to the redirect target but the stale authenticated view
     * stays put until a manual refresh. `Inertia::location()` answers an Inertia
     * request with a 409 carrying an `X-Inertia-Location` header, so the client
     * tears the whole app down and renders the login page from a clean slate.
     *
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        if ($request->wantsJson()) {
            return new JsonResponse('', 204);
        }

        return Inertia::location(route('login'));
    }
}
