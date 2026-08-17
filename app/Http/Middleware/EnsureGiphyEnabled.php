<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\GiphyClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate the Giphy endpoints on the feature being configured. With no API key the
 * picker is fully hidden client-side; this makes the server match — the search
 * and attach endpoints 404, so nothing leaks on an unconfigured deployment.
 */
final readonly class EnsureGiphyEnabled
{
    public function __construct(private GiphyClient $giphy) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->giphy->isEnabled(), 404);

        return $next($request);
    }
}
