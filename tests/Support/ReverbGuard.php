<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Refuse to run the browser suite without a Reverb server behind it.
 *
 * The realtime tests subscribe real Echo clients to a live Reverb, so a stopped
 * one fails them one by one with messages that name the product ("Expected to
 * see text [Live hello from Alice]") rather than the missing WebSocket server.
 * Read cold that is a broadcasting regression; it is an absent container, and
 * running the PHP gate is enough to leave one behind (issue #954).
 *
 * The container exits cleanly, which reads like Reverb's own restart signal:
 * `reverb:start` polls `laravel:reverb:restart` in the cache every five seconds
 * and stops the loop when the value changes. It is not that. The value is read
 * once at boot and compared for inequality, so an empty cache staying empty
 * across a flush never trips it. `reverb:start` is a SignalableCommandInterface
 * whose handler returns the previous exit code, and Symfony's Application then
 * `exit()`s with it, so a plain SIGTERM produces exactly the `Exited (0)` seen
 * here. In other words the mechanism is whatever stops the container, not the
 * suite talking to the cache, and no amount of guarding the cache would help.
 * Hence a preflight rather than a fix upstream: name the missing server once,
 * up front.
 */
final class ReverbGuard
{
    /**
     * Whether this process has already probed the server.
     */
    private static bool $probed = false;

    /**
     * Reverb's own defaults, used when neither the environment nor the
     * checkout's .env names a server.
     */
    public const string DEFAULT_HOST = '127.0.0.1';

    public const int DEFAULT_PORT = 8080;

    /**
     * Seconds to wait for the TCP handshake before calling the server absent.
     * Long enough for a loaded Docker host, short enough that a genuinely dead
     * endpoint reports in about the time it takes to read the message.
     */
    public const float CONNECT_TIMEOUT = 2.0;

    /**
     * The host and port the browser suite's Echo clients dial, resolved the way
     * config/broadcasting.php resolves them: the process environment first (CI
     * exports REVERB_HOST/REVERB_PORT over the .env it copied from
     * .env.example), then the checkout's .env, then Reverb's defaults.
     *
     * @return array{0: string, 1: int}
     */
    public static function endpoint(string $basePath): array
    {
        return [
            self::setting($basePath, 'REVERB_HOST') ?? self::DEFAULT_HOST,
            (int) (self::setting($basePath, 'REVERB_PORT') ?? self::DEFAULT_PORT),
        ];
    }

    /**
     * Abort the run, once, when nothing is listening where the browser suite
     * expects Reverb.
     *
     * Reported by writing the message and exiting rather than by throwing, for
     * the same reason StaleBundleGuard does: a thrown exception would surface as
     * one failure per test, which is the very shape of output this guard exists
     * to replace.
     */
    public static function ensureReverbIsRunning(string $basePath): void
    {
        if (self::$probed) {
            return;
        }

        self::$probed = true;

        $warning = self::unreachableWarning(...self::endpoint($basePath));

        if ($warning === null) {
            return;
        }

        fwrite(STDERR, PHP_EOL.$warning.PHP_EOL.PHP_EOL);

        exit(1);
    }

    /**
     * The message to show when nothing accepts a connection at the given
     * endpoint, or null when a server does.
     */
    public static function unreachableWarning(string $host, int $port): ?string
    {
        if (self::accepts($host, $port)) {
            return null;
        }

        return <<<MESSAGE
              Reverb is not accepting connections at {$host}:{$port}.

              The browser suite subscribes real Echo clients to a live Reverb server, so
              without one every realtime test fails as though broadcasting were broken.

                  ./vendor/bin/sail up -d reverb

              (running the PHP gate can leave that container stopped; `docker compose ps -a`
              shows it as Exited)
            MESSAGE;
    }

    /**
     * Whether a TCP connection to the given endpoint completes. The connection
     * is closed immediately: the handshake is the whole signal, and Reverb needs
     * no handshake of its own to answer it.
     *
     * A refused connection is the answer this guard is looking for, not a
     * problem, so its warning is swallowed by a handler rather than by `@` —
     * PHPUnit's error handler reports suppressed diagnostics anyway, and would
     * turn every negative probe into a test warning.
     */
    private static function accepts(string $host, int $port): bool
    {
        set_error_handler(static fn (): bool => true);

        try {
            $connection = fsockopen($host, $port, $errorNumber, $errorMessage, self::CONNECT_TIMEOUT);
        } finally {
            restore_error_handler();
        }

        if ($connection === false) {
            return false;
        }

        fclose($connection);

        return true;
    }

    /**
     * The value of an environment setting, preferring the process environment
     * over the checkout's .env, or null when neither defines a non-empty one.
     */
    private static function setting(string $basePath, string $key): ?string
    {
        $value = getenv($key);

        if (is_string($value) && $value !== '') {
            return $value;
        }

        return self::fromEnvFile($basePath, $key);
    }

    /**
     * The value the checkout's .env assigns to the given key, or null when it
     * assigns none.
     *
     * Parsed here rather than through Dotenv so this file stays standalone: the
     * runner and the tests both `require` it on its own, with no autoloader.
     */
    private static function fromEnvFile(string $basePath, string $key): ?string
    {
        $path = $basePath.'/.env';

        if (! is_readable($path)) {
            return null;
        }

        $contents = (string) file_get_contents($path);

        if (preg_match('/^[ \t]*'.preg_quote($key, '/').'[ \t]*=(.*)$/m', $contents, $matches) !== 1) {
            return null;
        }

        $value = trim($matches[1]);

        if (strlen($value) >= 2 && in_array($value[0], ['"', "'"], strict: true) && $value[-1] === $value[0]) {
            $value = substr($value, 1, -1);
        }

        return $value === '' ? null : $value;
    }
}
