<?php

declare(strict_types=1);

use Amp\Http\Server\Driver\DefaultHttpDriverFactory;
use Amp\Http\Server\SocketHttpServer;
use Pest\Browser\Drivers\LaravelHttpServer;
use Pest\Browser\Support\Port;

/**
 * pest-plugin-browser v4.3.1 serves the app in-process from an Amp server built
 * with `SocketHttpServer::createForDirectAccess()`'s defaults, which close an
 * idle HTTP/1 connection after 15 seconds. Browser tests routinely leave one
 * idle for longer — the event loop only runs while the PHP side awaits — and
 * Chrome reuses those sockets regardless of the advertised `Keep-Alive` window,
 * so the next document races the server's overdue close. The request that loses
 * comes back with status 0 and no bytes; when that is a stylesheet the page
 * renders unstyled and every geometry assertion reports a bogus layout
 * regression (issue #944). `tests/Browser/Support/LaravelHttpServer.php` shadows
 * the vendor class (required from `tests/Pest.php` before the autoloader can
 * load the original) and raises those timeouts past any plausible test duration.
 * These tests pin the shadow so a dependency bump or a moved `require` cannot
 * silently bring the 15-second window back.
 */
it('loads the patched LaravelHttpServer shadow instead of the vendor class', function (): void {
    $file = new ReflectionClass(LaravelHttpServer::class)->getFileName();

    expect($file)->toBe(dirname(__DIR__).'/Browser/Support/LaravelHttpServer.php');
});

it('keeps an idle connection open for far longer than a browser test can run', function (): void {
    expect(LaravelHttpServer::CONNECTION_TIMEOUT)->toBeGreaterThanOrEqual(600);
});

it('hands the configured timeouts to the Amp server it starts', function (): void {
    $server = new LaravelHttpServer('127.0.0.1', Port::find());
    $server->start();

    try {
        $socket = readPrivateProperty($server, 'socket');

        expect($socket)->toBeInstanceOf(SocketHttpServer::class);

        $driverFactory = readPrivateProperty($socket, 'httpDriverFactory');

        expect($driverFactory)->toBeInstanceOf(DefaultHttpDriverFactory::class)
            ->and(readPrivateProperty($driverFactory, 'streamTimeout'))
            ->toBe(LaravelHttpServer::CONNECTION_TIMEOUT)
            ->and(readPrivateProperty($driverFactory, 'connectionTimeout'))
            ->toBe(LaravelHttpServer::CONNECTION_TIMEOUT);
    } finally {
        $server->stop();
    }
});

it('gives a served document the means to re-request a stylesheet it lost', function (): void {
    $repaired = repairDocument('<html><head><link rel="stylesheet" href="/a.css"></head><body></body></html>');

    expect($repaired)->toContain('link[rel="stylesheet"]')
        ->toContain('pest-retry')
        // The failed <link> is the element the page and every assertion read, so
        // the retry has to re-point it rather than append a sibling.
        ->toContain('link.href =')
        ->and(mb_substr_count($repaired, '</head>'))->toBe(1);
});

it('carries the nonce of the document it is injected into', function (): void {
    $repaired = repairDocument('<html><head><script nonce="abc123"></script></head><body></body></html>');

    // script-src is 'strict-dynamic' with a per-request nonce; an un-nonced tag
    // would never run, and the repair would silently do nothing.
    expect($repaired)->toContain('<script nonce="abc123">(() => {');
});

it('leaves a document alone when it carries no nonce to borrow', function (): void {
    $repaired = repairDocument('<html><head></head><body></body></html>');

    expect($repaired)->toContain('<script>(() => {');
});

it('never touches a response that is not a document', function (): void {
    $css = 'body{color:red}</head>';

    expect(repairDocument($css, 'text/css'))->toBe($css)
        ->and(repairDocument('{"json":true}', 'application/json'))->toBe('{"json":true}');
});

it('leaves an html response with no head alone', function (): void {
    expect(repairDocument('<html><body>fragment</body></html>'))->toBe('<html><body>fragment</body></html>');
});

/**
 * Run a response body through the shadow's document repair.
 */
function repairDocument(string $content, string $contentType = 'text/html; charset=UTF-8'): string
{
    $server = new LaravelHttpServer('127.0.0.1', 1);

    $repair = new ReflectionMethod($server, 'withStylesheetRepair');

    $repaired = $repair->invoke($server, $content, $contentType);

    assert(is_string($repaired));

    return $repaired;
}

/**
 * Read a private property off a vendor object the plugin gives us no accessor for.
 */
function readPrivateProperty(object $object, string $property): mixed
{
    return new ReflectionProperty($object, $property)->getValue($object);
}
