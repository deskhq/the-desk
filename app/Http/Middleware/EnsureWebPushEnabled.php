<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\WebPushConfig;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate the push-subscription endpoints on the instance having a VAPID keypair.
 * Without one nothing can be signed, so a stored subscription could never be
 * delivered to — the endpoints 404 and the settings toggle stays hidden, which
 * keeps the two sides of the feature in agreement.
 */
class EnsureWebPushEnabled
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(WebPushConfig::configured(), 404);

        return $next($request);
    }
}
