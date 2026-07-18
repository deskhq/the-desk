<?php

namespace App\Http\Middleware;

use App\Enums\IntegrationScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces a single Sanctum ability (an {@see IntegrationScope}) on
 * the authenticated token. Every API route names the exact scope it needs, so a
 * token acts strictly within the abilities it was minted with — least-privilege
 * by default — and a request whose token lacks the scope is refused with a 403.
 */
class EnsureTokenScope
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        abort_unless(
            (bool) $request->user()?->tokenCan($scope),
            403,
            __('This token is missing the required “:scope” scope.', ['scope' => $scope]),
        );

        return $next($request);
    }
}
