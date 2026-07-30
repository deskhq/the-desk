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
 * the socket sits there unread for the whole sleep, so such a poll spends its
 * entire budget preventing the very thing it waits for. That is what failed the
 * forward-undo test in roughly two CI runs in five while it passed every time on
 * an unloaded machine, where the DELETE happens to complete inside the tail of
 * `click()`'s own await (#1077).
 *
 * `$page->wait()` is the yielding counterpart — Amp's `delay()` behind an
 * `await`, so the loop runs and the pending request is served — and the settle
 * scripts in `tests/Browser/Helpers.php` are better again wherever what is being
 * waited on is observable in the page.
 */

/**
 * Whether the given PHP source blocks the event loop, by calling the `Sleep`
 * facade or one of the bare sleep functions.
 *
 * Read through the tokenizer rather than by pattern, because the rule has to be
 * stated in prose in the very files it governs: the comment explaining the trap
 * sits next to the poll it explains, and a scan that could not tell code from
 * commentary would fail the gate on its own documentation. Tokenizing also gets
 * PHP's own rules for free — `USLEEP()` is the same function as `usleep()`, and
 * `$page->sleep()` is not that function at all.
 */
function blocksTheEventLoop(string $source): bool
{
    $tokens = array_values(array_filter(
        token_get_all($source),
        static fn (array|string $token): bool => ! is_array($token) || ! in_array($token[0], [
            T_COMMENT,
            T_DOC_COMMENT,
            T_WHITESPACE,
            T_CONSTANT_ENCAPSED_STRING,
            T_ENCAPSED_AND_WHITESPACE,
            T_INLINE_HTML,
        ], true),
    ));

    foreach ($tokens as $index => $token) {
        if (! is_array($token)) {
            continue;
        }

        if (! in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            continue;
        }

        // The trailing segment is what names the function or the class, so a
        // `use`-d `Sleep` and a fully qualified `\Illuminate\Support\Sleep` are
        // one case rather than two.
        $name = strtolower((string) strrchr('\\'.$token[1], '\\'));
        $next = $tokens[$index + 1] ?? null;
        $previous = $tokens[$index - 1] ?? null;

        // A method of the same name on something else is not this function, and
        // neither is a declaration of one.
        if (is_array($previous) && in_array($previous[0], [
            T_OBJECT_OPERATOR,
            T_NULLSAFE_OBJECT_OPERATOR,
            T_DOUBLE_COLON,
            T_FUNCTION,
        ], true)) {
            continue;
        }

        if ($name === '\sleep' && is_array($next) && $next[0] === T_DOUBLE_COLON) {
            return true;
        }

        if (in_array($name, ['\sleep', '\usleep'], true) && $next === '(') {
            return true;
        }
    }

    return false;
}

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

test('the blocking-call scan reads code rather than prose', function (string $source, bool $blocks): void {
    expect(blocksTheEventLoop('<?php '.$source))->toBe($blocks);
})->with([
    'the Sleep facade' => ['Sleep::usleep(100_000);', true],
    'the Sleep facade, fully qualified' => ['\Illuminate\Support\Sleep::for(1)->second();', true],
    'the Sleep facade, spaced' => ['Sleep :: sleep(1);', true],
    'a bare usleep' => ['usleep(100000);', true],
    'a bare sleep' => ['sleep(1);', true],
    'a bare sleep, spaced and shouted' => ['USLEEP (1);', true],
    'the rule stated in a comment' => ['// never Sleep::usleep(1) here', false],
    'the rule stated in a docblock' => ['/** Not usleep(1), which blocks. */', false],
    'the rule stated in a string' => ['$page->script("sleep(1)");', false],
    'the yielding counterpart' => ['$page->wait(0.1);', false],
    'a method that merely shares the name' => ['$clock->sleep(1);', false],
    'a function that merely shares the name' => ['browserSleep(1);', false],
]);

test('no browser test blocks the event loop the application is served from', function (): void {
    $offenders = array_keys(array_filter(
        browserSuiteSources(),
        blocksTheEventLoop(...),
    ));

    expect(array_values($offenders))->toBe([]);
});
