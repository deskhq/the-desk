<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates the identity provider against the SCIM API with an instance-wide
 * bearer token.
 *
 * SCIM is a machine-to-machine REST API, not a login form, so it carries none of
 * Fortify's session/guard machinery: the IdP presents `Authorization: Bearer
 * <token>` and this middleware compares it (in constant time) to the configured
 * secret, rejecting anything that does not match with a SCIM-shaped 401. The
 * routes are only mounted when a token is configured (see SsoServiceProvider), so
 * a missing secret means the endpoint does not exist rather than being open.
 */
class EnsureScimBearerToken
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $configured = (string) config('sso.scim.token');
        $presented = (string) $request->bearerToken();

        if ($presented === '' || ! hash_equals($configured, $presented)) {
            return $this->unauthorized();
        }

        return $next($request);
    }

    /**
     * Build a SCIM-conformant 401 error response.
     */
    private function unauthorized(): JsonResponse
    {
        return new JsonResponse([
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:Error'],
            'detail' => 'Unauthorized',
            'status' => '401',
        ], 401, [
            'Content-Type' => 'application/scim+json',
            'WWW-Authenticate' => 'Bearer',
        ]);
    }
}
