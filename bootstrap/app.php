<?php

declare(strict_types=1);

use App\Http\Middleware\AddContentSecurityPolicyHeaders;
use App\Http\Middleware\EnsureIntegrationsEnabled;
use App\Http\Middleware\EnsurePasskeysEnabled;
use App\Http\Middleware\EnsurePasswordLoginEnabled;
use App\Http\Middleware\EnsureTokenScope;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PreventDestructiveDemoActions;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SetTeamUrlDefaults;
use App\Http\Middleware\ThrottlePasswordResetRequests;
use App\Http\Middleware\TrackActiveSession;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\ExceptionResponse;
use Inertia\Inertia;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind a TLS-terminating reverse proxy the container receives plain
        // HTTP, so trust the proxy's X-Forwarded-* headers — otherwise
        // $request->isSecure() is false and every absolute URL Laravel generates
        // (route(), redirect()->route(), Fortify redirects, mail links) comes out
        // http:// on an https:// page and the browser blocks it as mixed content.
        // '*' trusts the calling proxy, which is safe for this stack: the app
        // publishes to loopback / the compose network and is only reachable
        // through the proxy that sets these headers.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // Incoming webhooks authenticate by their opaque URL token, not a session,
        // so they carry no CSRF token — exempt them so external senders (curl,
        // Grafana, Sentry) can post without one.
        $middleware->validateCsrfTokens(except: ['webhooks/incoming/*']);

        // Route-middleware aliases for the public REST API: `integrations` 404s
        // the whole surface when the platform toggle is off; `scope` enforces a
        // single Sanctum ability per endpoint.
        $middleware->alias([
            'integrations' => EnsureIntegrationsEnabled::class,
            'scope' => EnsureTokenScope::class,
        ]);

        // AddContentSecurityPolicyHeaders comes first: it sets the request's CSP
        // nonce on the Vite facade on the way in, so it has to run before
        // HandleInertiaRequests renders the Blade shell. The API group is left
        // out deliberately — a JSON response has no DOM for a policy to protect.
        $middleware->web(append: [
            AddContentSecurityPolicyHeaders::class,
            EnsurePasswordLoginEnabled::class,
            EnsurePasskeysEnabled::class,
            ThrottlePasswordResetRequests::class,
            PreventDestructiveDemoActions::class,
            HandleAppearance::class,
            SetLocale::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            SetTeamUrlDefaults::class,
            TrackActiveSession::class,
            EnsureUserIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson(),
        );

        // Render branded "The Desk" Inertia error pages for the common HTTP
        // statuses, so they inherit the app shell, theme, and shared
        // translations (withSharedData resolves the Inertia middleware even for
        // exceptions thrown outside a matched route, e.g. a 404).
        //
        // 500 and 503 are intentionally excluded: they fall through to the
        // self-contained Blade fallbacks (resources/views/errors/*.blade.php),
        // which render even when Inertia or the session is unavailable. API /
        // JSON requests keep the JSON response from shouldRenderJsonWhen above.
        Inertia::handleExceptionsUsing(function (ExceptionResponse $response): ?ExceptionResponse {
            $request = $response->request;

            if ($request->is('api/*') || $request->expectsJson()) {
                return null;
            }

            if (in_array($response->statusCode(), [403, 404, 419, 429], true)) {
                return $response
                    ->render('Error', ['status' => $response->statusCode()])
                    ->withSharedData();
            }

            return null;
        });
    })->create();
