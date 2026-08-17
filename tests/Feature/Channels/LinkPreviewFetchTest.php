<?php

declare(strict_types=1);

use App\Support\FetchLinkPreview;
use App\Support\HostResolver;
use App\Support\Http\GuardedEgress;
use App\Support\Http\OutboundUrlGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Support\StubHostResolver;

function fetcherWith(HostResolver $resolver): FetchLinkPreview
{
    return new FetchLinkPreview(new GuardedEgress(new OutboundUrlGuard($resolver)));
}

test('unfurls Open Graph metadata from a public URL', function (): void {
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

    expect(fetcherWith(StubHostResolver::returning())->handle('https://example.com'))->toBe([
        'title' => 'Hello',
        'description' => 'A page',
        'image' => 'https://example.com/img.png',
        'siteName' => 'Example',
    ]);
});

test('falls back to the title tag and host when og tags are absent', function (): void {
    Http::fake(['https://example.com' => Http::response(
        '<html><head><title>Just a title</title></head></html>',
        200,
        ['Content-Type' => 'text/html'],
    )]);

    expect(fetcherWith(StubHostResolver::returning())->handle('https://example.com'))->toBe([
        'title' => 'Just a title',
        'description' => null,
        'image' => null,
        'siteName' => 'example.com',
    ]);
});

test('ignores whitespace-only meta content', function (): void {
    Http::fake(['https://example.com' => Http::response(
        '<html><head><title>T</title><meta property="og:description" content="   "></head></html>',
        200,
        ['Content-Type' => 'text/html'],
    )]);

    expect(fetcherWith(StubHostResolver::returning())->handle('https://example.com')['description'])->toBeNull();
});

test('returns null when the page has no title at all', function (): void {
    Http::fake(['https://example.com' => Http::response(
        '<html><body><p>nothing to see</p></body></html>',
        200,
        ['Content-Type' => 'text/html'],
    )]);

    expect(fetcherWith(StubHostResolver::returning())->handle('https://example.com'))->toBeNull();
});

test('returns null for an empty body', function (): void {
    Http::fake(['https://example.com' => Http::response('   ', 200, ['Content-Type' => 'text/html'])]);

    expect(fetcherWith(StubHostResolver::returning())->handle('https://example.com'))->toBeNull();
});

test('resolves a protocol-relative og:image against the base scheme', function (): void {
    Http::fake(['https://example.com' => Http::response(
        '<html><head><title>T</title><meta property="og:image" content="//cdn.example.com/i.png"></head></html>',
        200,
        ['Content-Type' => 'text/html'],
    )]);

    expect(fetcherWith(StubHostResolver::returning())->handle('https://example.com')['image'])
        ->toBe('https://cdn.example.com/i.png');
});

test('resolves a root-relative og:image against the base origin', function (): void {
    Http::fake(['https://example.com' => Http::response(
        '<html><head><title>T</title><meta property="og:image" content="/img/a.png"></head></html>',
        200,
        ['Content-Type' => 'text/html'],
    )]);

    expect(fetcherWith(StubHostResolver::returning())->handle('https://example.com')['image'])
        ->toBe('https://example.com/img/a.png');
});

test('blocks a private, loopback, link-local or reserved host', function (string $ip): void {
    Http::fake();

    expect(fetcherWith(StubHostResolver::returning(default: [$ip]))->handle('https://internal.test'))->toBeNull();

    Http::assertNothingSent();
})->with(['10.0.0.5', '127.0.0.1', '169.254.169.254', '192.168.1.1', '172.16.0.1']);

test('rejects a non-http(s) scheme', function (): void {
    Http::fake();

    expect(fetcherWith(StubHostResolver::returning())->handle('ftp://example.com'))->toBeNull();

    Http::assertNothingSent();
});

test('rejects a malformed URL', function (): void {
    Http::fake();

    expect(fetcherWith(StubHostResolver::returning())->handle('http://foo:bar'))->toBeNull();

    Http::assertNothingSent();
});

test('rejects a URL with no host', function (): void {
    Http::fake();

    expect(fetcherWith(StubHostResolver::returning())->handle('http:///just/a/path'))->toBeNull();

    Http::assertNothingSent();
});

test('rejects a host that does not resolve', function (): void {
    Http::fake();

    expect(fetcherWith(StubHostResolver::returning(default: []))->handle('https://ghost.example'))->toBeNull();

    Http::assertNothingSent();
});

test('follows a safe redirect to the final page', function (): void {
    Http::fake([
        'https://example.com/start' => Http::response('', 301, ['Location' => 'https://example.com/final']),
        'https://example.com/final' => Http::response(
            '<html><head><title>Final</title></head></html>',
            200,
            ['Content-Type' => 'text/html'],
        ),
    ]);

    expect(fetcherWith(StubHostResolver::returning())->handle('https://example.com/start')['title'])->toBe('Final');
});

test('rejects a redirect with no Location', function (): void {
    Http::fake(['https://example.com/go' => Http::response('', 302, [])]);

    expect(fetcherWith(StubHostResolver::returning())->handle('https://example.com/go'))->toBeNull();
});

test('re-validates the host on each redirect hop', function (): void {
    Http::fake(['https://safe.test/go' => Http::response('', 302, ['Location' => 'https://internal.test/secret'])]);

    $resolver = StubHostResolver::returning([
        'safe.test' => ['93.184.216.34'],
        'internal.test' => ['10.0.0.5'],
    ]);

    expect(fetcherWith($resolver)->handle('https://safe.test/go'))->toBeNull();
});

test('gives up after too many redirects', function (): void {
    Http::fake(['https://example.com/loop' => Http::response('', 302, ['Location' => 'https://example.com/loop'])]);

    expect(fetcherWith(StubHostResolver::returning())->handle('https://example.com/loop'))->toBeNull();
});

test('rejects an unsuccessful response', function (): void {
    Http::fake(['https://example.com' => Http::response('nope', 404)]);

    expect(fetcherWith(StubHostResolver::returning())->handle('https://example.com'))->toBeNull();
});

test('rejects a non-html response', function (): void {
    Http::fake(['https://example.com' => Http::response('{"a":1}', 200, ['Content-Type' => 'application/json'])]);

    expect(fetcherWith(StubHostResolver::returning())->handle('https://example.com'))->toBeNull();
});

test('rejects an oversized response', function (): void {
    Http::fake(['https://example.com' => Http::response(
        '<html><head><title>Huge</title></head></html>',
        200,
        ['Content-Type' => 'text/html', 'Content-Length' => (string) (3 * 1024 * 1024)],
    )]);

    expect(fetcherWith(StubHostResolver::returning())->handle('https://example.com'))->toBeNull();
});

test('resolves once and pins the connection, so a rebinding second answer is never dialled', function (): void {
    $resolver = StubHostResolver::rebinding([['93.184.216.34'], ['169.254.169.254']]);

    captureTransportOptions(
        ['https://example.com/page' => Http::response(
            '<html><head><title>Pinned</title></head></html>',
            200,
            ['Content-Type' => 'text/html'],
        )],
        $captured,
    );

    expect(fetcherWith($resolver)->handle('https://example.com/page')['title'])->toBe('Pinned')
        ->and($resolver->lookups)->toBe(['example.com'])
        ->and($captured[0]['curl'][CURLOPT_RESOLVE])->toBe(['example.com:443:93.184.216.34'])
        // curl is never allowed to follow a redirect itself: a hop it took is a
        // hop the guard never saw.
        ->and($captured[0]['allow_redirects'])->toBeFalse();
});

test('truncates an oversized body rather than refusing it, since only the head is parsed', function (): void {
    Http::fake(['https://example.com' => Http::response(
        '<html><head><title>Long</title></head><body>'.str_repeat('x', 3 * 1024 * 1024).'</body></html>',
        200,
        ['Content-Type' => 'text/html'],
    )]);

    expect(fetcherWith(StubHostResolver::returning())->handle('https://example.com')['title'])->toBe('Long');
});

test('pins every redirect hop to its own vetted address', function (): void {
    $resolver = StubHostResolver::returning([
        'start.test' => ['93.184.216.34'],
        'final.test' => ['93.184.216.35'],
    ]);

    captureTransportOptions([
        'http://start.test/go' => Http::response('', 301, ['Location' => 'https://final.test/here']),
        'https://final.test/here' => Http::response(
            '<html><head><title>Hopped</title></head></html>',
            200,
            ['Content-Type' => 'text/html'],
        ),
    ], $captured);

    expect(fetcherWith($resolver)->handle('http://start.test/go')['title'])->toBe('Hopped')
        ->and($captured[0]['curl'][CURLOPT_RESOLVE])->toBe(['start.test:80:93.184.216.34'])
        ->and($captured[1]['curl'][CURLOPT_RESOLVE])->toBe(['final.test:443:93.184.216.35']);
});

test('unfurls an internal URL when the operator has turned the guard off', function (): void {
    config(['integrations.webhooks.block_private_urls' => false]);

    captureTransportOptions(
        ['http://wiki.internal/page' => Http::response(
            '<html><head><title>Runbook</title></head></html>',
            200,
            ['Content-Type' => 'text/html'],
        )],
        $captured,
    );

    expect(fetcherWith(StubHostResolver::returning(default: ['10.0.0.5']))->handle('http://wiki.internal/page')['title'])
        ->toBe('Runbook')
        ->and($captured[0])->not->toHaveKey('curl');
});

test('degrades to no preview when the remote host is unreachable, and does not retry it immediately', function (): void {
    $attempts = 0;

    Http::fake(function () use (&$attempts): never {
        $attempts++;

        throw new ConnectionException('no route to host');
    });

    $fetcher = fetcherWith(StubHostResolver::returning());

    expect($fetcher->handle('https://example.com'))->toBeNull()
        ->and($fetcher->handle('https://example.com'))->toBeNull()
        ->and($attempts)->toBe(1);
});

test('caches the result so the same URL is only fetched once', function (): void {
    Http::fake(['https://example.com' => Http::response(
        '<html><head><title>Cached</title></head></html>',
        200,
        ['Content-Type' => 'text/html'],
    )]);

    $fetcher = fetcherWith(StubHostResolver::returning());
    $first = $fetcher->handle('https://example.com');

    expect($fetcher->handle('https://example.com'))->toBe($first);

    Http::assertSentCount(1);
});
