<?php

use App\Support\FetchLinkPreview;
use App\Support\HostResolver;
use Illuminate\Support\Facades\Http;

/**
 * A HostResolver stub that returns fixed IPs per host, so the SSRF guard can be
 * exercised without touching real DNS.
 *
 * @param  array<string, array<int, string>>  $map
 * @param  array<int, string>  $default
 */
function resolverReturning(array $map = [], array $default = ['93.184.216.34']): HostResolver
{
    return new class($map, $default) extends HostResolver
    {
        /**
         * @param  array<string, array<int, string>>  $map
         * @param  array<int, string>  $default
         */
        public function __construct(private array $map, private array $default) {}

        public function resolve(string $host): array
        {
            return $this->map[$host] ?? $this->default;
        }
    };
}

function fetcherWith(HostResolver $resolver): FetchLinkPreview
{
    return new FetchLinkPreview($resolver);
}

test('unfurls Open Graph metadata from a public URL', function () {
    Http::fake(['https://example.com' => Http::response(
        '<html><head>'
        .'<meta property="og:title" content="Hello">'
        .'<meta property="og:description" content="A page">'
        .'<meta property="og:image" content="https://example.com/img.png">'
        .'<meta property="og:site_name" content="Example">'
        .'</head></html>',
        200,
        ['Content-Type' => 'text/html'],
    )]);

    expect(fetcherWith(resolverReturning())->handle('https://example.com'))->toBe([
        'title' => 'Hello',
        'description' => 'A page',
        'image' => 'https://example.com/img.png',
        'siteName' => 'Example',
    ]);
});

test('falls back to the title tag and host when og tags are absent', function () {
    Http::fake(['https://example.com' => Http::response(
        '<html><head><title>Just a title</title></head></html>',
        200,
        ['Content-Type' => 'text/html'],
    )]);

    expect(fetcherWith(resolverReturning())->handle('https://example.com'))->toBe([
        'title' => 'Just a title',
        'description' => null,
        'image' => null,
        'siteName' => 'example.com',
    ]);
});

test('ignores whitespace-only meta content', function () {
    Http::fake(['https://example.com' => Http::response(
        '<html><head><title>T</title><meta property="og:description" content="   "></head></html>',
        200,
        ['Content-Type' => 'text/html'],
    )]);

    expect(fetcherWith(resolverReturning())->handle('https://example.com')['description'])->toBeNull();
});

test('returns null when the page has no title at all', function () {
    Http::fake(['https://example.com' => Http::response(
        '<html><body><p>nothing to see</p></body></html>',
        200,
        ['Content-Type' => 'text/html'],
    )]);

    expect(fetcherWith(resolverReturning())->handle('https://example.com'))->toBeNull();
});

test('returns null for an empty body', function () {
    Http::fake(['https://example.com' => Http::response('   ', 200, ['Content-Type' => 'text/html'])]);

    expect(fetcherWith(resolverReturning())->handle('https://example.com'))->toBeNull();
});

test('resolves a protocol-relative og:image against the base scheme', function () {
    Http::fake(['https://example.com' => Http::response(
        '<html><head><title>T</title><meta property="og:image" content="//cdn.example.com/i.png"></head></html>',
        200,
        ['Content-Type' => 'text/html'],
    )]);

    expect(fetcherWith(resolverReturning())->handle('https://example.com')['image'])
        ->toBe('https://cdn.example.com/i.png');
});

test('resolves a root-relative og:image against the base origin', function () {
    Http::fake(['https://example.com' => Http::response(
        '<html><head><title>T</title><meta property="og:image" content="/img/a.png"></head></html>',
        200,
        ['Content-Type' => 'text/html'],
    )]);

    expect(fetcherWith(resolverReturning())->handle('https://example.com')['image'])
        ->toBe('https://example.com/img/a.png');
});

test('blocks a private, loopback, link-local or reserved host', function (string $ip) {
    Http::fake();

    expect(fetcherWith(resolverReturning(default: [$ip]))->handle('https://internal.test'))->toBeNull();

    Http::assertNothingSent();
})->with(['10.0.0.5', '127.0.0.1', '169.254.169.254', '192.168.1.1', '172.16.0.1']);

test('rejects a non-http(s) scheme', function () {
    Http::fake();

    expect(fetcherWith(resolverReturning())->handle('ftp://example.com'))->toBeNull();

    Http::assertNothingSent();
});

test('rejects a malformed URL', function () {
    Http::fake();

    expect(fetcherWith(resolverReturning())->handle('http://foo:bar'))->toBeNull();

    Http::assertNothingSent();
});

test('rejects a URL with no host', function () {
    Http::fake();

    expect(fetcherWith(resolverReturning())->handle('http:///just/a/path'))->toBeNull();

    Http::assertNothingSent();
});

test('rejects a host that does not resolve', function () {
    Http::fake();

    expect(fetcherWith(resolverReturning(default: []))->handle('https://ghost.example'))->toBeNull();

    Http::assertNothingSent();
});

test('follows a safe redirect to the final page', function () {
    Http::fake([
        'https://example.com/start' => Http::response('', 301, ['Location' => 'https://example.com/final']),
        'https://example.com/final' => Http::response(
            '<html><head><title>Final</title></head></html>',
            200,
            ['Content-Type' => 'text/html'],
        ),
    ]);

    expect(fetcherWith(resolverReturning())->handle('https://example.com/start')['title'])->toBe('Final');
});

test('rejects a redirect with no Location', function () {
    Http::fake(['https://example.com/go' => Http::response('', 302, [])]);

    expect(fetcherWith(resolverReturning())->handle('https://example.com/go'))->toBeNull();
});

test('re-validates the host on each redirect hop', function () {
    Http::fake(['https://safe.test/go' => Http::response('', 302, ['Location' => 'https://internal.test/secret'])]);

    $resolver = resolverReturning([
        'safe.test' => ['93.184.216.34'],
        'internal.test' => ['10.0.0.5'],
    ]);

    expect(fetcherWith($resolver)->handle('https://safe.test/go'))->toBeNull();
});

test('gives up after too many redirects', function () {
    Http::fake(['https://example.com/loop' => Http::response('', 302, ['Location' => 'https://example.com/loop'])]);

    expect(fetcherWith(resolverReturning())->handle('https://example.com/loop'))->toBeNull();
});

test('rejects an unsuccessful response', function () {
    Http::fake(['https://example.com' => Http::response('nope', 404)]);

    expect(fetcherWith(resolverReturning())->handle('https://example.com'))->toBeNull();
});

test('rejects a non-html response', function () {
    Http::fake(['https://example.com' => Http::response('{"a":1}', 200, ['Content-Type' => 'application/json'])]);

    expect(fetcherWith(resolverReturning())->handle('https://example.com'))->toBeNull();
});

test('rejects an oversized response', function () {
    Http::fake(['https://example.com' => Http::response(
        '<html><head><title>Huge</title></head></html>',
        200,
        ['Content-Type' => 'text/html', 'Content-Length' => (string) (3 * 1024 * 1024)],
    )]);

    expect(fetcherWith(resolverReturning())->handle('https://example.com'))->toBeNull();
});

test('caches the result so the same URL is only fetched once', function () {
    Http::fake(['https://example.com' => Http::response(
        '<html><head><title>Cached</title></head></html>',
        200,
        ['Content-Type' => 'text/html'],
    )]);

    $fetcher = fetcherWith(resolverReturning());
    $first = $fetcher->handle('https://example.com');

    expect($fetcher->handle('https://example.com'))->toBe($first);

    Http::assertSentCount(1);
});
