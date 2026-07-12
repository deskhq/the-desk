<?php

declare(strict_types=1);

namespace Tests\Browser\Support;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reset per-request auth and session state at the start of every request.
 *
 * The pest browser server (Pest\Browser\Drivers\LaravelHttpServer) handles every
 * request in one long-lived process, reusing singleton services that a normal
 * per-process request gets fresh. Two things leak across requests as a result:
 *
 *  - The session store keeps its attributes: `Store::loadSession()` does
 *    `array_replace($this->attributes, ...)`, so a brand-new session (a second
 *    browser context with no cookie) inherits the previous request's login,
 *    silently authenticating client two as client one.
 *  - The session guard caches the first user it resolves.
 *
 * Flushing the store and forgetting the guards up front forces each request to
 * rebuild its session and re-resolve auth from its own cookie — which is what
 * lets two isolated browser contexts act as two different signed-in users.
 *
 * This is browser-test-only: the helper registers it on the kernel at runtime
 * (see tests/Browser/Helpers.php), so it never touches the production stack.
 */
final class ForgetGuardsPerRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        session()->flush();
        Auth::forgetGuards();

        return $next($request);
    }
}
