<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Apply the request's locale — the signed-in user's stored preference, or the
     * application default for guests — so `__()` and the shared `locale` /
     * `translations` props all resolve against it.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale($request->user()?->locale->value ?? config('app.locale'));

        return $next($request);
    }
}
