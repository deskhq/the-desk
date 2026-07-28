<?php

declare(strict_types=1);

namespace Pest\Browser\Drivers;

use Amp\ByteStream\ReadableResourceStream;
use Amp\Http\Cookie\RequestCookie;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Driver\DefaultHttpDriverFactory;
use Amp\Http\Server\HttpServer as AmpHttpServer;
use Amp\Http\Server\HttpServerStatus;
use Amp\Http\Server\Request as AmpRequest;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Testing\Concerns\WithoutExceptionHandlingHandler;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Uri;
use Pest\Browser\Contracts\HttpServer;
use Pest\Browser\Exceptions\ServerNotFoundException;
use Pest\Browser\Execution;
use Pest\Browser\GlobalState;
use Pest\Browser\Playwright\Playwright;
use Psr\Log\NullLogger;
use Symfony\Component\Mime\MimeTypes;
use Throwable;

/**
 * Shadow of `pest-plugin-browser` v4.3.1's
 * `Pest\Browser\Drivers\LaravelHttpServer`, loaded from `tests/Pest.php` before
 * the vendor autoloader can load the original.
 *
 * The vendor class builds its Amp server with `createForDirectAccess()`'s
 * defaults, which give an HTTP/1 connection a 15-second idle timeout. A browser
 * test routinely leaves a keep-alive connection idle for longer than that — the
 * PHP side is asserting, seeding, or driving the Playwright protocol, and the
 * event loop only runs while it awaits. Chrome does not honour the advertised
 * `Keep-Alive: timeout=15` and reuses those sockets anyway, so the next document
 * races the server's overdue close: whichever request lands on the doomed socket
 * comes back with no protocol, status 0 and zero bytes. A script that loses is
 * re-requested; a stylesheet is not, so the document renders completely unstyled
 * and every assertion that reads a rendered box reports a bogus layout
 * regression instead (issue #944).
 *
 * There are three behavioural changes, and everything else is a verbatim copy:
 *
 * 1. `start()` raises the connection and stream timeouts past any plausible
 *    test duration, so the server never closes a connection the browser still
 *    considers usable. This is what fixes the reuse race above.
 * 2. `withStylesheetRepair()` gives every served document the means to
 *    re-request a stylesheet it lost anyway. The timeout is not the only way to
 *    drop one — a CPU-starved CI runner loses assets for reasons this process
 *    never observes — and no browser retries a stylesheet on its own. The
 *    retry is automatic rather than opt-in per test, which is what issue #944
 *    asks for.
 * 3. `parseMultipartBody()` fills in the request bag the vendor class leaves
 *    empty behind a `@TODO files...`. That class parses urlencoded bodies only
 *    and calls `Request::create()` with no files at all, so `$request->file(...)`
 *    is null for every upload a browser makes: the pre-upload 422s, the chip
 *    vanishes, and no attachment behaviour can be covered in a browser test at
 *    all (that is what retired `ComposerAttachmentTest` in #483). #920 needs a
 *    real staged attachment on a phone viewport to prove its remove target, so
 *    the shadow parses the multipart body into `UploadedFile`s.
 *
 * Remove this shadow (and its `require` in `tests/Pest.php`) once the plugin
 * fixes asset delivery and uploads upstream; `tests/Unit/BrowserAssetDeliveryTest.php`
 * and `tests/Unit/BrowserUploadDeliveryTest.php` pin the shadow until then.
 *
 * @internal
 *
 * @codeCoverageIgnore
 */
final class LaravelHttpServer implements HttpServer
{
    /**
     * How long, in seconds, an idle connection is kept open.
     *
     * Amp's default is 15s, which a browser test outlives whenever the PHP side
     * works for longer than that between two documents. An hour is beyond any
     * plausible single test, and the server is torn down with the process.
     */
    public const int CONNECTION_TIMEOUT = 3600;

    /**
     * The underlying socket server instance, if any.
     */
    private ?AmpHttpServer $socket = null;

    /**
     * The original asset URL, if set.
     */
    private ?string $originalAssetUrl = null;

    /**
     * The last throwable that occurred during the server's execution.
     */
    private ?Throwable $lastThrowable = null;

    /**
     * Creates a new laravel http server instance.
     */
    public function __construct(
        public readonly string $host,
        public readonly int $port,
    ) {
        //
    }

    /**
     * Destroy the server instance and stop listening for incoming connections.
     */
    public function __destruct()
    {
        // @codeCoverageIgnoreStart
        // $this->stop();
    }

    /**
     * Rewrite the given URL to match the server's host and port.
     */
    public function rewrite(string $url): string
    {
        if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            $url = mb_ltrim($url, '/');

            $url = '/'.$url;
        }

        $parts = parse_url($url);
        $queryParameters = [];
        $path = $parts['path'] ?? '/';
        parse_str($parts['query'] ?? '', $queryParameters);

        return (string) Uri::of($this->url())
            ->withPath($path)
            ->withQuery($queryParameters);
    }

    /**
     * Start the server and listen for incoming connections.
     */
    public function start(): void
    {
        if ($this->socket instanceof AmpHttpServer) {
            return;
        }

        $logger = new NullLogger;

        $this->socket = $server = SocketHttpServer::createForDirectAccess(
            $logger,
            httpDriverFactory: new DefaultHttpDriverFactory(
                $logger,
                streamTimeout: self::CONNECTION_TIMEOUT,
                connectionTimeout: self::CONNECTION_TIMEOUT,
            ),
        );

        $server->expose("{$this->host}:{$this->port}");
        $server->start(
            new ClosureRequestHandler($this->handleRequest(...)),
            new DefaultErrorHandler,
        );
    }

    /**
     * Stop the server and close all connections.
     */
    public function stop(): void
    {
        // @codeCoverageIgnoreStart
        if ($this->socket instanceof AmpHttpServer) {
            $this->flush();

            if ($this->socket instanceof AmpHttpServer) {
                if (in_array($this->socket->getStatus(), [HttpServerStatus::Starting, HttpServerStatus::Started], true)) {
                    $this->socket->stop();
                }

                $this->socket = null;
            }
        }
    }

    /**
     * Flush pending requests and close all connections.
     */
    public function flush(): void
    {
        if (! $this->socket instanceof AmpHttpServer) {
            return;
        }

        Execution::instance()->tick();

        $this->lastThrowable = null;
    }

    /**
     * Bootstrap the server and set the application URL.
     */
    public function bootstrap(): void
    {
        $this->start();

        $url = $this->url();

        config(['app.url' => $url]);

        config(['cors.paths' => ['*']]);

        if (app()->bound('url')) {
            $urlGenerator = app('url');

            assert($urlGenerator instanceof UrlGenerator);

            $this->setOriginalAssetUrl($urlGenerator->asset(''));

            $urlGenerator->useOrigin($url);
            $urlGenerator->useAssetOrigin($url);
            $urlGenerator->forceScheme('http');
        }
    }

    /**
     * Get the last throwable that occurred during the server's execution.
     */
    public function lastThrowable(): ?Throwable
    {
        return $this->lastThrowable;
    }

    /**
     * Throws the last throwable if it should be thrown.
     *
     * @throws Throwable
     */
    public function throwLastThrowableIfNeeded(): void
    {
        if (! $this->lastThrowable instanceof Throwable) {
            return;
        }

        $exceptionHandler = app(ExceptionHandler::class);

        if ($exceptionHandler instanceof WithoutExceptionHandlingHandler) {
            throw $this->lastThrowable;
        }
    }

    /**
     * Get the public path for the given path.
     */
    private function url(): string
    {
        if (! $this->socket instanceof AmpHttpServer) {
            throw new ServerNotFoundException('The HTTP server is not running.');
        }

        return sprintf('http://%s:%d', $this->host, $this->port);
    }

    /**
     * Sets the original asset URL.
     */
    private function setOriginalAssetUrl(string $url): void
    {
        $this->originalAssetUrl = mb_rtrim($url, '/');
    }

    /**
     * Handle the incoming request and return a response.
     */
    private function handleRequest(AmpRequest $request): Response
    {
        GlobalState::flush();

        if (Execution::instance()->isWaiting() === false) {
            Execution::instance()->tick();
        }

        $uri = $request->getUri();
        $path = in_array($uri->getPath(), ['', '0'], true) ? '/' : $uri->getPath();
        $query = $uri->getQuery() ?? ''; // @phpstan-ignore-line
        $fullPath = $path.($query !== '' ? '?'.$query : '');
        $absoluteUrl = mb_rtrim($this->url(), '/').$fullPath;

        $filepath = public_path($path);
        if (file_exists($filepath) && ! is_dir($filepath)) {
            return $this->asset($filepath);
        }

        $kernel = app()->make(HttpKernel::class);

        $contentType = $request->getHeader('content-type') ?? '';
        $method = mb_strtoupper($request->getMethod());
        $rawBody = (string) $request->getBody();
        $parameters = [];
        $files = [];
        if ($method !== 'GET' && str_starts_with(mb_strtolower($contentType), 'application/x-www-form-urlencoded')) {
            parse_str($rawBody, $parameters);
        }
        if ($method !== 'GET' && str_starts_with(mb_strtolower($contentType), 'multipart/form-data')) {
            [$parameters, $files] = $this->parseMultipartBody($rawBody, $contentType);
        }
        $cookies = array_map(fn (RequestCookie $cookie): string => urldecode($cookie->getValue()), $request->getCookies());
        $cookies = array_merge($cookies, test()->prepareCookiesForRequest()); // @phpstan-ignore-line
        /** @var array<string, string> $serverVariables */
        $serverVariables = test()->serverVariables(); // @phpstan-ignore-line

        $symfonyRequest = Request::create(
            $absoluteUrl,
            $method,
            $parameters,
            $cookies,
            $files,
            $serverVariables,
            $rawBody
        );

        $symfonyRequest->headers->add($request->getHeaders());

        // Set the Host header to match the configured host for subdomain routing
        $configuredHost = Playwright::host();
        if ($configuredHost !== null) {
            $hostHeader = sprintf('%s:%d', $configuredHost, $this->port);
            $symfonyRequest->headers->set('Host', $hostHeader);
            // Also set SERVER_NAME for Laravel routing
            $symfonyRequest->server->set('SERVER_NAME', $configuredHost);
            $symfonyRequest->server->set('HTTP_HOST', $hostHeader);
        }

        $debug = config('app.debug');

        try {
            config(['app.debug' => false]);

            $response = $kernel->handle($laravelRequest = Request::createFromBase($symfonyRequest));
        } catch (Throwable $e) {
            $this->lastThrowable = $e;

            throw $e;
        } finally {
            config(['app.debug' => $debug]);
        }

        $kernel->terminate($laravelRequest, $response);

        $this->discardTemporaryUploads($files);

        if (property_exists($response, 'exception') && $response->exception !== null) {
            assert($response->exception instanceof Throwable);

            $this->lastThrowable = $response->exception;
        }

        $content = $response->getContent();

        if ($content === false) {
            try {
                ob_start();
                $response->sendContent();
            } finally {
                // @phpstan-ignore-next-line
                $content = mb_trim(ob_get_clean());
            }
        }

        return new Response(
            $response->getStatusCode(),
            $response->headers->all(), // @phpstan-ignore-line
            $this->withStylesheetRepair((string) $content, (string) $response->headers->get('Content-Type')),
        );
    }

    /**
     * Split a `multipart/form-data` body into request parameters and uploads.
     *
     * The vendor class parses urlencoded bodies only, so every file a browser
     * posts arrives as nothing at all. Each part is read here into either a
     * scalar field or a temporary file wrapped in an `UploadedFile` marked as a
     * test upload — `is_uploaded_file()` is false for anything this process
     * wrote itself, and without that flag Laravel's `file` rule rejects it.
     *
     * Field names go back through `parse_str`, so `tags[]` and `filters[type]`
     * keep the shape PHP would have given them; file names are walked by hand
     * for the same reason, since `parse_str` cannot carry an object.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function parseMultipartBody(string $body, string $contentType): array
    {
        if (preg_match('/boundary="?([^";,]+)"?/i', $contentType, $matches) !== 1) {
            return [[], []];
        }

        $fields = [];
        $files = [];
        $segments = explode('--'.$matches[1], $body);

        // The first segment is the preamble before the opening boundary and the
        // last is the epilogue after the closing one; neither is a part.
        foreach (array_slice($segments, 1, -1) as $segment) {
            // Byte functions throughout: a part's content is arbitrary binary,
            // and the `mb_` family this class otherwise uses would rewrite any
            // byte that is not valid UTF-8 into a replacement character.
            $split = explode("\r\n\r\n", ltrim($segment, "\r\n"), 2);

            if (count($split) !== 2) {
                continue;
            }

            [$rawHeaders, $content] = $split;
            $content = (string) preg_replace('/\r\n$/', '', $content);
            $name = $this->headerParameter($rawHeaders, 'name');

            if ($name === null) {
                continue;
            }

            $filename = $this->headerParameter($rawHeaders, 'filename');

            if ($filename === null) {
                $fields[] = urlencode($name).'='.urlencode($content);

                continue;
            }

            $this->assignFile($files, $name, $this->temporaryUpload($content, $filename, $rawHeaders));
        }

        parse_str(implode('&', $fields), $parameters);

        return [$parameters, $files];
    }

    /**
     * Read one `Content-Disposition` parameter (`name`, `filename`) off a part's
     * headers. Only the quoted form is matched, which is the only one a browser
     * or an HTTP client this suite drives emits.
     */
    private function headerParameter(string $rawHeaders, string $parameter): ?string
    {
        $pattern = sprintf('/;\s*%s="([^"]*)"/i', preg_quote($parameter, '/'));

        return preg_match($pattern, $rawHeaders, $matches) === 1 ? $matches[1] : null;
    }

    /**
     * Spill one part's bytes to a temporary file and wrap them as an upload.
     */
    private function temporaryUpload(string $content, string $filename, string $rawHeaders): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'pest-upload-');
        file_put_contents($path, $content);

        // Stop at the parameter separator: `text/plain; charset=utf-8` is a
        // client mime type of `text/plain`, not `text/plain;`.
        $mimeType = preg_match('/^content-type:\s*([^;\s]+)/im', $rawHeaders, $matches) === 1
            ? $matches[1]
            : null;

        return new UploadedFile($path, $filename, $mimeType, null, true);
    }

    /**
     * Place an upload in the file bag under the bracket path its field name
     * spells out: `file`, `photos[]` and `docs[cover]` all land where PHP would
     * have put them.
     *
     * @param  array<string, mixed>  $files
     */
    private function assignFile(array &$files, string $name, UploadedFile $file): void
    {
        if (preg_match('/^([^\[\]]+)((?:\[[^\[\]]*\])*)$/', $name, $matches) !== 1) {
            return;
        }

        preg_match_all('/\[([^\[\]]*)\]/', $matches[2], $brackets);

        $keys = array_merge([$matches[1]], $brackets[1]);
        $cursor = &$files;

        foreach ($keys as $depth => $key) {
            $isLeaf = $depth === count($keys) - 1;

            if ($key === '') {
                $cursor[] = $isLeaf ? $file : [];
                $key = array_key_last($cursor);
            } elseif ($isLeaf) {
                $cursor[$key] = $file;
            } elseif (! isset($cursor[$key]) || ! is_array($cursor[$key])) {
                $cursor[$key] = [];
            }

            if ($isLeaf) {
                return;
            }

            $cursor = &$cursor[$key];
        }
    }

    /**
     * Delete the temporary files of any upload the application did not move.
     *
     * A request that stores its upload has already moved the file away; one that
     * fails validation leaves it behind, and the browser suite posts enough of
     * them to matter over a full run.
     *
     * @param  array<string, mixed>  $files
     */
    private function discardTemporaryUploads(array $files): void
    {
        array_walk_recursive($files, function (mixed $file): void {
            if ($file instanceof UploadedFile && is_file($file->getPathname())) {
                @unlink($file->getPathname());
            }
        });
    }

    /**
     * Give every served document the means to re-request a stylesheet it lost.
     *
     * Raising the connection timeout above stops this server closing a socket
     * the browser still wants, which is what drops an asset on a developer
     * machine. It is not the only way to lose one: on a CPU-starved runner the
     * request can fail for reasons this process never sees, and the browser
     * does not retry a stylesheet on its own. So each document carries a small
     * repair that re-points any `link[rel=stylesheet]` left without a `sheet`
     * at its own href, which makes the browser fetch it again.
     *
     * Re-pointing the existing element rather than appending a new one matters:
     * the failed `<link>` is the one the page — and any assertion reading the
     * document — looks at, and a sibling tag would leave it empty forever.
     *
     * The `error` listener is the path that carries its weight. It fires while
     * the document is still loading, so the retry it starts is one more
     * subresource the `load` event waits for, and a navigation therefore does
     * not complete until the stylesheet is really there. The later sweeps only
     * cover a failure that never raised an error at all.
     */
    private function withStylesheetRepair(string $content, string $contentType): string
    {
        if (! str_contains($contentType, 'text/html')) {
            return $content;
        }

        $position = mb_strpos($content, '</head>');

        if ($position === false) {
            return $content;
        }

        // script-src is 'strict-dynamic' with a per-request nonce, under which
        // an un-nonced tag — inline or sourced — never runs. Reusing the nonce
        // already in the document keeps this working without reaching into the
        // container for a value the response has itself.
        $nonce = preg_match('/nonce="([^"]+)"/', $content, $matches) === 1
            ? sprintf(' nonce="%s"', $matches[1])
            : '';

        $repair = <<<'JS'
        (() => {
            const attempts = new WeakMap();

            const repair = (link) => {
                const attempted = attempts.get(link) ?? 0;

                if (link.sheet !== null || attempted >= 3) {
                    return;
                }

                attempts.set(link, attempted + 1);

                // A fresh query string, so the retry cannot be answered from
                // the cache entry the failed request may have poisoned.
                link.href = `${link.href.split('#')[0].split('?')[0]}?pest-retry=${attempted + 1}`;
            };

            const sweep = () => document.querySelectorAll('link[rel="stylesheet"]').forEach(repair);

            document.querySelectorAll('link[rel="stylesheet"]').forEach((link) => {
                link.addEventListener('error', () => repair(link));
            });

            addEventListener('load', sweep);
            setTimeout(sweep, 2000);
            setTimeout(sweep, 6000);
        })();
        JS;

        return substr_replace($content, sprintf('<script%s>%s</script>', $nonce, $repair), $position, 0);
    }

    /**
     * Return an asset response.
     */
    private function asset(string $filepath): Response
    {
        $file = fopen($filepath, 'r');

        if ($file === false) {
            return new Response(404);
        }

        $mimeTypes = new MimeTypes;
        $contentType = $mimeTypes->getMimeTypes(pathinfo($filepath, PATHINFO_EXTENSION));

        $contentType = $contentType[0] ?? 'application/octet-stream';

        if (str_ends_with($filepath, '.js')) {
            $temporaryStream = fopen('php://temp', 'r+');
            assert($temporaryStream !== false, 'Failed to open temporary stream.');

            // @phpstan-ignore-next-line
            $temporaryContent = fread($file, (int) filesize($filepath));

            assert($temporaryContent !== false, 'Failed to open temporary stream.');

            $content = $this->rewriteAssetUrl($temporaryContent);

            fwrite($temporaryStream, $content);

            rewind($temporaryStream);

            $file = $temporaryStream;
        }

        return new Response(200, [
            'Content-Type' => $contentType,
        ], new ReadableResourceStream($file));
    }

    /**
     * Rewrite the asset URL in the given content.
     */
    private function rewriteAssetUrl(string $content): string
    {
        if ($this->originalAssetUrl === null) {
            return $content;
        }

        return str_replace($this->originalAssetUrl, $this->url(), $content);
    }
}
