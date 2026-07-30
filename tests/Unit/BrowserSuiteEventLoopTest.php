<?php

declare(strict_types=1);

/**
 * The browser suite serves the application from an Amp server running inside the
 * test process, and that server's event loop only runs while the PHP side awaits
 * — which is why `tests/Browser/Support/LaravelHttpServer.php` has to raise its
 * connection timeouts past any plausible test duration in the first place.
 *
 * A blocking sleep therefore does not merely fail to help a test waiting on a
 * request: it is what stops the request being served. Whatever the browser put on
 * the socket sits there unread for the whole sleep, so a poll built out of
 * `usleep` spends its entire budget preventing the very thing it waits for. That
 * is what failed the forward-undo test in roughly two CI runs in five while it
 * passed every time on an unloaded machine, where the DELETE happens to complete
 * inside the tail of `click()`'s own await (#1077).
 *
 * `$page->wait()` is the yielding counterpart — Amp's `delay()` behind an
 * `await`, so the loop runs and the pending request is served — and the settle
 * scripts in `tests/Browser/Helpers.php` are better again wherever what is being
 * waited on is observable in the page.
 */

/**
 * Every source the browser suite runs, keyed by its repository-relative path.
 *
 * @return array<string, string>
 */
function browserSuiteSources(): array
{
    $root = dirname(__DIR__, 2);

    $paths = [
        ...(array) glob($root.'/tests/Browser/*.php'),
        ...(array) glob($root.'/tests/Browser/Support/*.php'),
    ];

    return array_combine(
        array_map(static fn (string $path): string => substr($path, strlen($root) + 1), $paths),
        array_map(static fn (string $path): string => (string) file_get_contents($path), $paths),
    );
}

test('no browser test blocks the event loop the application is served from', function (): void {
    // `Sleep::` covers the facade's whole surface, whose every form — `sleep`,
    // `usleep`, `for()->seconds()` — blocks alike. The bare calls are matched
    // without allowing a space before the parenthesis, so that a comment saying
    // "a sleep (see #1077)" is prose rather than an offender; Pint would reject
    // the spaced call form anyway.
    $blocking = '/\bSleep::|(?<![\w:$>-])u?sleep\(/';

    $offenders = array_keys(array_filter(
        browserSuiteSources(),
        static fn (string $contents): bool => preg_match($blocking, $contents) === 1,
    ));

    expect(array_values($offenders))->toBe([]);
});
