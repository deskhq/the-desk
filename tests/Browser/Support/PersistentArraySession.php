<?php

declare(strict_types=1);

namespace Tests\Browser\Support;

use Illuminate\Session\ArraySessionHandler;

/**
 * Pin one `array` session handler that outlives the per-test application refresh.
 *
 * The pest browser server (Pest\Browser\Drivers\LaravelHttpServer) serves every
 * request from a single long-lived process, but Laravel's TestCase rebuilds the
 * application — and with it the `array` driver's in-memory session store — before
 * each test. The browser keeps firing asynchronous requests (echo channel auth,
 * the timeline's mark-as-read, Inertia partial reloads) that can land after the
 * store was rebuilt. Those requests then load an empty session, resolve as a
 * guest, and Inertia follows the resulting `302 → /login` by navigating the whole
 * page to the login screen — failing whichever test is mid-assertion. This is the
 * flake behind the intermittent "…on the page initially with the url […/login]"
 * browser-suite failures.
 *
 * Reattaching a single handler that survives the refresh keeps every session id
 * ever issued resolvable no matter which application instance handles the request,
 * so a signed-in browser stays signed in for the life of its test. Session ids are
 * regenerated on every login, so carrying prior tests' data forward is inert.
 *
 * This is browser-test-only: the helper is called from each browser test's setup
 * (see tests/Browser/Helpers.php), so it never touches the production stack.
 */
final class PersistentArraySession
{
    private static ?ArraySessionHandler $handler = null;

    /**
     * Point the freshly rebuilt session store at the shared, refresh-surviving handler.
     */
    public static function attach(): void
    {
        self::$handler ??= new ArraySessionHandler((int) config('session.lifetime', 120));

        app('session')->driver()->setHandler(self::$handler);
    }
}
