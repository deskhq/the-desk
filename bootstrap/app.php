<?php

declare(strict_types=1);

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SetTeamUrlDefaults;
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
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            SetLocale::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            SetTeamUrlDefaults::class,
            TrackActiveSession::class,
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
