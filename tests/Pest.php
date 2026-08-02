<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ReverbGuard;
use Tests\Support\StaleBundleGuard;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Browser Plugin Shadow (#786)
|--------------------------------------------------------------------------
|
| pest-plugin-browser v4.3.1 runs each retry attempt of an awaitable browser
| call under a hardcoded 1000ms protocol budget. On CPU-starved CI runners
| those attempts expire routinely, poisoning the Playwright websocket with
| stale error responses and re-driving non-idempotent clicks (issue #786).
| This patched copy of the plugin's Execution class gives each attempt the
| remaining outer budget instead. It must be declared before the autoloader
| can load the vendor class, hence the require at the very top of this file.
|
*/

require_once __DIR__.'/Browser/Support/Execution.php';

/*
|--------------------------------------------------------------------------
| Browser Plugin Shadow (#944)
|--------------------------------------------------------------------------
|
| The same plugin serves the app in-process from an Amp server built with
| `createForDirectAccess()`'s defaults, which close an HTTP/1 connection after
| 15s idle. Browser tests routinely leave one idle for longer while the PHP
| side works, and Chrome reuses it regardless, so the next document races the
| server's overdue close and silently loses an asset — a dropped stylesheet
| renders the page unstyled and every geometry assertion then reports a bogus
| layout regression (issue #944). This patched copy raises those timeouts past
| any plausible test duration. Same story as the shadow above: it must be
| declared before the autoloader can load the vendor class.
|
*/

require_once __DIR__.'/Browser/Support/LaravelHttpServer.php';

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Integration Test Case (#1111)
|--------------------------------------------------------------------------
|
| The same booted application and the same migrated database as `Feature`,
| and deliberately so: what separates the two suites is not what they are
| given but what they drive. An `Integration` test constructs the module and
| calls it; a `Feature` test goes through `route()`. Before this suite existed
| the split was "needs a database" against "doesn't", which is why 115 files
| in `tests/Feature` never name a route — they were unit-shaped tests exiled
| there to get a database, and modules that were perfectly constructible could
| only be reached through a rendered page. ADR-0012 has the full account.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Integration');

// The slash-command unit tests exercise command copy through the translator, so
// they need the application booted (but no database).
pest()->extend(TestCase::class)->in('Unit/SlashCommands');

// The Giphy client unit test drives config/Http/Cache facades, so it needs the
// application booted (but no database).
pest()->extend(TestCase::class)->in('Unit/Support/GiphyClientTest.php');

// The Reverb config unit test reads the broadcasting config, so it needs the
// application booted (but no database).
pest()->extend(TestCase::class)->in('Unit/Support/ReverbConfigTest.php');

// The web push config unit test reads the VAPID config, so it needs the
// application booted (but no database).
pest()->extend(TestCase::class)->in('Unit/Support/WebPushConfigTest.php');

// The availability unit test hydrates a User's casts and reads the app timezone,
// so it needs the application booted — but deliberately no database: taking the
// instant as a parameter is what lets the window arithmetic be stated without a
// saved row (#1148).
pest()->extend(TestCase::class)->in('Unit/Support/UserAvailabilityTest.php');

// The OpenAPI spec test diffs the document against the live route table, so it
// needs the application booted (but no database).
pest()->extend(TestCase::class)->in('Unit/OpenApiSpecTest.php');

// The Reverb secret guidance test checks documented commands against the ones
// Artisan actually registers, so it needs the application booted (but no
// database).
pest()->extend(TestCase::class)->in('Unit/ReverbSecretGuidanceTest.php');

/*
|--------------------------------------------------------------------------
| Browser (E2E) Test Case
|--------------------------------------------------------------------------
|
| Browser tests drive real Playwright browsers against an in-process HTTP
| server (see pestphp/pest-plugin-browser). They exercise the realtime Echo/
| Reverb paths that headless feature tests cannot, so each one runs against a
| live Reverb server. They are tagged `browser` and excluded from the default
| coverage gate; run them with `composer test:browser` (see README).
|
| That in-process server hands the browser the *compiled* assets under
| `public/build`, so a bundle older than the working tree fails these tests as
| though the application were broken — and it fails them on whatever landed most
| recently, which reads as a regression in those very PRs rather than as a stale
| bundle (issue #949). StaleBundleGuard sweeps the bundled sources once per
| process and stops the run with the rebuild command instead.
|
| The live Reverb server is the other half of that story: without one, every
| realtime test fails on the message it was waiting for, which reads as a
| broadcasting regression rather than as the stopped container it is — and
| running the PHP gate is enough to leave one behind (issue #954). ReverbGuard
| probes it once per process and names it.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->group('browser')
    ->beforeEach(function (): void {
        StaleBundleGuard::ensureFreshBundle(base_path());
        ReverbGuard::ensureReverbIsRunning(base_path());

        useReverbForBrowserTests();
    })
    ->in('Browser');

// The plugin's default 5 s assertion timeout flakes on slow CI runners: the
// 80-reply virtualized thread panel and the search panel need longer to render
// before their assertions can pass (#581). Assertions retry and return as soon
// as they hold, so raising the ceiling only slows genuinely failing tests.
pest()->browser()->timeout(15000);

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', fn () => $this->toBe(1));

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something(): void
{
    // ..
}

require_once __DIR__.'/Helpers.php';
require_once __DIR__.'/Browser/Helpers.php';
require_once __DIR__.'/Feature/Auth/Sso/Helpers.php';
require_once __DIR__.'/Feature/Scim/Helpers.php';
