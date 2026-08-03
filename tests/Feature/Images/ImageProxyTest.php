<?php

declare(strict_types=1);

use App\Actions\Images\PurgeCachedProxyImages;
use App\Data\LinkPreviewData;
use App\Enums\LinkPreviewStatus;
use App\Models\MessageLinkPreview;
use App\Models\User;
use App\Support\HostResolver;
use App\Support\Images\FetchRemoteImage;
use App\Support\Images\ImageProxy;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\StubHostResolver;

/**
 * Point every hostname at a public IP so the SSRF guard's delivery-time
 * resolution can run without touching real DNS.
 *
 * @param  array<string, array<int, string>>  $map
 * @param  array<int, string>  $default
 */
function resolveHostsTo(array $map = [], array $default = ['93.184.216.34']): void
{
    app()->instance(HostResolver::class, StubHostResolver::returning($map, $default));
}

/**
 * A remote-image response with the given type and body.
 */
function imageResponse(string $mime = 'image/png', string $body = 'PNGBYTES', array $headers = []): PromiseInterface
{
    return Http::response($body, 200, array_merge(['Content-Type' => $mime], $headers));
}

beforeEach(function (): void {
    Storage::fake(FetchRemoteImage::DISK);
    resolveHostsTo();
});

it('signs a remote url into a relative first-party route', function (): void {
    $url = ImageProxy::url('https://cdn.example.test/cat.png');

    expect($url)->toStartWith('/images/proxy?')
        ->and($url)->toContain('signature=')
        ->and($url)->toContain(urlencode('https://cdn.example.test/cat.png'));
});

it('has nothing to proxy for a null, blank or non-http url', function (?string $input): void {
    expect(ImageProxy::url($input))->toBeNull();
})->with([
    'null' => [null],
    'blank' => ['   '],
    'data uri' => ['data:image/png;base64,AAAA'],
    'javascript' => ['javascript:alert(1)'],
]);

it('fetches, caches and serves a remote image from our own origin', function (): void {
    Http::fake(['cdn.example.test/*' => imageResponse('image/png', 'PNGBYTES')]);

    $response = $this->actingAs(User::factory()->create())
        ->get((string) ImageProxy::url('https://cdn.example.test/cat.png'));

    $response->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect($response->streamedContent())->toBe('PNGBYTES');

    Storage::disk(FetchRemoteImage::DISK)
        ->assertExists(FetchRemoteImage::DIRECTORY.'/'.hash('sha256', 'https://cdn.example.test/cat.png'));
});

it('serves a second request from the cache without refetching', function (): void {
    Http::fake(['cdn.example.test/*' => imageResponse()]);

    $user = User::factory()->create();
    $url = (string) ImageProxy::url('https://cdn.example.test/cat.png');

    $this->actingAs($user)->get($url)->assertOk();
    $this->actingAs($user)->get($url)->assertOk();

    Http::assertSentCount(1);
});

it('refetches when the cached file has been purged from disk', function (): void {
    Http::fake(['cdn.example.test/*' => imageResponse()]);

    $user = User::factory()->create();
    $url = (string) ImageProxy::url('https://cdn.example.test/cat.png');

    $this->actingAs($user)->get($url)->assertOk();

    Storage::disk(FetchRemoteImage::DISK)
        ->delete(FetchRemoteImage::DIRECTORY.'/'.hash('sha256', 'https://cdn.example.test/cat.png'));

    $this->actingAs($user)->get($url)->assertOk();

    Http::assertSentCount(2);
});

it('follows a redirect, re-checking each hop against the guard', function (): void {
    Http::fake([
        'cdn.example.test/cat.png' => Http::response('', 302, ['Location' => '/real/cat.png']),
        'cdn.example.test/real/cat.png' => imageResponse('image/gif', 'GIFBYTES'),
    ]);

    $response = $this->actingAs(User::factory()->create())
        ->get((string) ImageProxy::url('https://cdn.example.test/cat.png'));

    $response->assertOk()->assertHeader('Content-Type', 'image/gif');
});

it('refuses a redirect whose next hop resolves to a private address', function (): void {
    resolveHostsTo([
        'cdn.example.test' => ['93.184.216.34'],
        'internal.example.test' => ['10.0.0.5'],
    ]);

    Http::fake([
        'cdn.example.test/cat.png' => Http::response('', 302, ['Location' => 'https://internal.example.test/cat.png']),
        'internal.example.test/*' => imageResponse(),
    ]);

    $this->actingAs(User::factory()->create())
        ->get((string) ImageProxy::url('https://cdn.example.test/cat.png'))
        ->assertNotFound();

    Http::assertNotSent(fn ($request): bool => str_contains((string) $request->url(), 'internal.example.test'));
});

it('pins each hop to the address the guard vetted, and never lets curl follow one', function (): void {
    resolveHostsTo([
        'cdn.example.test' => ['93.184.216.34'],
        'images.example.test' => ['93.184.216.35'],
    ]);

    captureTransportOptions([
        'https://cdn.example.test/cat.png' => Http::response('', 302, ['Location' => 'https://images.example.test/cat.png']),
        'https://images.example.test/cat.png' => imageResponse(),
    ], $captured);

    $this->actingAs(User::factory()->create())
        ->get((string) ImageProxy::url('https://cdn.example.test/cat.png'))
        ->assertOk();

    expect($captured[0]['curl'][CURLOPT_RESOLVE])->toBe(['cdn.example.test:443:93.184.216.34'])
        ->and($captured[0]['allow_redirects'])->toBeFalse()
        ->and($captured[1]['curl'][CURLOPT_RESOLVE])->toBe(['images.example.test:443:93.184.216.35'])
        ->and($captured[1]['allow_redirects'])->toBeFalse();
});

it('rejects an unsigned or tampered url', function (): void {
    $signed = (string) ImageProxy::url('https://cdn.example.test/cat.png');
    $user = User::factory()->create();

    $this->actingAs($user)->get('/images/proxy?url='.urlencode('https://evil.test/probe.png'))
        ->assertForbidden();

    $this->actingAs($user)->get(str_replace('cat.png', 'other.png', $signed))
        ->assertForbidden();
});

it('requires an authenticated session', function (): void {
    $this->get((string) ImageProxy::url('https://cdn.example.test/cat.png'))
        ->assertRedirect('/login');
});

it('404s rather than fetching a target the SSRF guard blocks', function (string $url, array $resolvesTo): void {
    Http::fake();
    resolveHostsTo(default: $resolvesTo);

    $this->actingAs(User::factory()->create())
        ->get((string) ImageProxy::url($url))
        ->assertNotFound();

    Http::assertNothingSent();
})->with([
    // Rejected by name/literal address, before any DNS lookup.
    'literal loopback' => ['http://127.0.0.1/cat.png', ['93.184.216.34']],
    'localhost' => ['http://localhost/cat.png', ['93.184.216.34']],
    'cloud metadata' => ['http://169.254.169.254/latest/meta-data', ['93.184.216.34']],
    // Rejected at delivery-time resolution: a public-looking name pointing inside.
    'rebound hostname' => ['https://internal.example.test/cat.png', ['127.0.0.1']],
]);

it('404s on a response that is not a proxyable image', function (string $mime): void {
    Http::fake(['cdn.example.test/*' => imageResponse($mime, 'BODY')]);

    $this->actingAs(User::factory()->create())
        ->get((string) ImageProxy::url('https://cdn.example.test/cat.png'))
        ->assertNotFound();
})->with([
    'html' => ['text/html'],
    // SVG can carry script, so serving one from our own origin is exactly what
    // the tightened img-src exists to prevent.
    'svg' => ['image/svg+xml'],
]);

it('404s on an error response, an empty body, or an oversized image', function (Closure $fake): void {
    Http::fake(['cdn.example.test/*' => $fake()]);

    $this->actingAs(User::factory()->create())
        ->get((string) ImageProxy::url('https://cdn.example.test/cat.png'))
        ->assertNotFound();
})->with([
    'not found' => [fn (): Closure => fn () => Http::response('nope', 404)],
    'empty body' => [fn (): Closure => fn (): PromiseInterface => imageResponse('image/png', '')],
    'declared oversize' => [fn (): Closure => fn (): PromiseInterface => imageResponse('image/png', 'X', ['Content-Length' => (string) (6 * 1024 * 1024)])],
    'actual oversize' => [fn (): Closure => fn (): PromiseInterface => imageResponse('image/png', str_repeat('X', 5242881))],
    'redirect with no location' => [fn (): Closure => fn () => Http::response('', 302)],
]);

it('404s when the remote host is unreachable, and does not retry it immediately', function (): void {
    $attempts = 0;

    Http::fake(function () use (&$attempts): never {
        $attempts++;

        throw new ConnectionException('no route to host');
    });

    $user = User::factory()->create();
    $url = (string) ImageProxy::url('https://cdn.example.test/cat.png');

    $this->actingAs($user)->get($url)->assertNotFound();
    $this->actingAs($user)->get($url)->assertNotFound();

    expect($attempts)->toBe(1);
});

it('404s on a redirect loop that never reaches an image', function (): void {
    Http::fake(['cdn.example.test/*' => Http::response('', 302, ['Location' => '/again.png'])]);

    $this->actingAs(User::factory()->create())
        ->get((string) ImageProxy::url('https://cdn.example.test/cat.png'))
        ->assertNotFound();
});

it('404s when the signed url carries no target', function (): void {
    $url = URL::signedRoute('images.proxy', ['url' => ''], absolute: false);

    $this->actingAs(User::factory()->create())->get($url)->assertNotFound();
});

it('purges cached images past their TTL and keeps fresh ones', function (): void {
    $disk = Storage::disk(FetchRemoteImage::DISK);
    $disk->put(FetchRemoteImage::DIRECTORY.'/stale', 'old');
    $disk->put(FetchRemoteImage::DIRECTORY.'/fresh', 'new');

    touch(
        $disk->path(FetchRemoteImage::DIRECTORY.'/stale'),
        now()->subSeconds(FetchRemoteImage::CACHE_TTL_SECONDS + 60)->getTimestamp(),
    );

    expect(app(PurgeCachedProxyImages::class)->handle())->toBe(1);

    $disk->assertMissing(FetchRemoteImage::DIRECTORY.'/stale');
    $disk->assertExists(FetchRemoteImage::DIRECTORY.'/fresh');
});

it('remembers a fetched image across requests in the cache store', function (): void {
    Http::fake(['cdn.example.test/*' => imageResponse()]);

    app(FetchRemoteImage::class)->handle('https://cdn.example.test/cat.png');

    expect(Cache::get('image-proxy:'.hash('sha256', 'https://cdn.example.test/cat.png')))
        ->toBeArray();
});

it('routes a link-preview thumbnail through the proxy so the linked site never sees the reader', function (): void {
    $preview = new MessageLinkPreview([
        'url' => 'https://news.example.test/story',
        'status' => LinkPreviewStatus::Ready,
        'title' => 'A story',
        'description' => null,
        'image_url' => 'https://news.example.test/hero.jpg',
        'site_name' => 'News',
    ]);

    expect(LinkPreviewData::fromModel($preview)->imageUrl)
        ->toBe(ImageProxy::url('https://news.example.test/hero.jpg'));
});

it('leaves a link preview without a thumbnail alone', function (): void {
    $preview = new MessageLinkPreview([
        'url' => 'https://news.example.test/story',
        'status' => LinkPreviewStatus::Ready,
        'title' => 'A story',
        'description' => null,
        'image_url' => null,
        'site_name' => 'News',
    ]);

    expect(LinkPreviewData::fromModel($preview)->imageUrl)->toBeNull();
});
