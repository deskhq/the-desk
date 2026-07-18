<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the whole integrations platform behind the `INTEGRATIONS_ENABLED`
 * toggle. When the operator turns it off the public API surface 404s outright —
 * the routes behave as if they do not exist — so a disabled instance exposes no
 * integration endpoints at all.
 */
class EnsureIntegrationsEnabled
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('integrations.enabled'), 404);

        return $next($request);
    }
}
