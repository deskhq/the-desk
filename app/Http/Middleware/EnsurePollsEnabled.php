<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate the poll endpoints on the feature being enabled. With POLLS_ENABLED=false
 * the builder is fully hidden client-side; this makes the server match — the
 * create, vote, and close endpoints 404, so nothing leaks when polls are off.
 */
class EnsurePollsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('polls.enabled'), 404);

        return $next($request);
    }
}
