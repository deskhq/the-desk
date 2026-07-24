<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
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

// The OpenAPI spec test diffs the document against the live route table, so it
// needs the application booted (but no database).
pest()->extend(TestCase::class)->in('Unit/OpenApiSpecTest.php');

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
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->group('browser')
    ->beforeEach(function (): void {
        useReverbForBrowserTests();
    })
    ->in('Browser');

// The plugin's default 5 s assertion timeout flakes on slow CI runners: the
// 80-reply virtualized thread panel and the search page need longer to render
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

require_once __DIR__.'/Browser/Helpers.php';
require_once __DIR__.'/Feature/Auth/Sso/Helpers.php';
require_once __DIR__.'/Feature/Scim/Helpers.php';
