<?php

declare(strict_types=1);

use App\Support\Csp\TheDeskPolicy;
use Illuminate\Support\Facades\Vite;
use Spatie\Csp\Policy;

/**
 * Pull the nonce out of a `script-src 'nonce-…'` source expression.
 */
function cspNonceFrom(string $header): string
{
    expect($header)->toMatch("/'nonce-[A-Za-z0-9+\/=_-]+'/");

    preg_match("/'nonce-([A-Za-z0-9+\/=_-]+)'/", $header, $matches);

    return $matches[1];
}

beforeEach(function (): void {
    // Vite reads public/hot off the filesystem, so a `npm run dev` left running
    // in another terminal would put the whole file in hot mode and splice the
    // dev-server origin into the directives asserted below. Pin the hot state
    // the way the posture below is pinned: point Vite at a hot file that does
    // not exist — unique per test, so nothing can create it underneath us — and
    // let the hot-mode test opt back in explicitly.
    Vite::useHotFile(sys_get_temp_dir().'/'.uniqid('the-desk-vite-not-hot-', true));

    // .env.example turns report-only on for local development, so every test
    // states the posture it is asserting rather than inheriting the ambient one.
    config([
        'csp.enabled' => true,
        'csp.report_only' => false,
        'csp.frame_ancestors' => 'none',
        'csp.extra' => [
            'script-src' => '',
            'style-src' => '',
            'img-src' => '',
            'connect-src' => '',
            'frame-src' => '',
            'font-src' => '',
        ],
    ]);
});

test('web responses carry the policy', function (): void {
    $header = $this->get(route('home'))->assertOk()->headers->get('Content-Security-Policy');

    expect($header)
        ->toContain("default-src 'self'")
        ->toContain("style-src 'self' 'unsafe-inline'")
        ->toContain("img-src 'self' data: blob:")
        ->toContain("font-src 'self'")
        // blob: lets a composer recording play back and have its waveform
        // decoded before it is ever uploaded.
        ->toContain("connect-src 'self' blob:")
        ->toContain("media-src 'self' blob:")
        ->toContain("worker-src 'self'")
        ->toContain("frame-src 'none'");
});

test('the directives that do not fall back to default-src are stated explicitly', function (string $directive): void {
    $header = (string) $this->get(route('home'))->headers->get('Content-Security-Policy');

    expect($header)->toContain($directive);
})->with([
    // Neither inherits default-src, so a policy that omits them leaves the
    // matching attack (a swapped <base href>, a form posted off-origin)
    // entirely unrestricted — which is exactly what the scanner flags.
    'base-uri' => ["base-uri 'self'"],
    'form-action' => ["form-action 'self'"],
]);

test('object-src is denied outright rather than inheriting default-src', function (): void {
    $header = (string) $this->get(route('home'))->headers->get('Content-Security-Policy');

    // It would fall back to default-src 'self'; nothing here renders an
    // <object>/<embed>, and their plugin documents are outside script-src.
    expect($header)->toContain("object-src 'none'");
});

test('api responses carry no policy — they have no dom to protect', function (): void {
    $response = $this->getJson('/api/v1/channels');

    expect($response->headers->get('Content-Security-Policy'))->toBeNull();
    expect($response->headers->get('Content-Security-Policy-Report-Only'))->toBeNull();
});

test('script-src is a nonce plus strict-dynamic, never unsafe-inline', function (): void {
    $header = (string) $this->get(route('home'))->headers->get('Content-Security-Policy');

    expect($header)
        ->toContain("'strict-dynamic'")
        ->not->toContain("script-src 'self' 'unsafe-inline'");
});

test('the nonce in the header is the one rendered into the blade shell', function (): void {
    $response = $this->get(route('home'))->assertOk();

    $nonce = cspNonceFrom((string) $response->headers->get('Content-Security-Policy'));

    expect($response->getContent())->toContain('nonce="'.$nonce.'"');
});

test('the nonce differs across requests', function (): void {
    $first = cspNonceFrom((string) $this->get(route('home'))->headers->get('Content-Security-Policy'));

    // Every real request builds a fresh container, which is what makes the
    // scoped nonce single-use; a test reuses one app, so drop the scoped
    // instances the way the request lifecycle would.
    $this->app->forgetScopedInstances();

    $second = cspNonceFrom((string) $this->get(route('home'))->headers->get('Content-Security-Policy'));

    expect($second)->not->toBe($first);
});

test('connect-src allow-lists the derived reverb websocket origin', function (): void {
    config([
        'app.url' => 'https://chat.example.test',
        'broadcasting.connections.reverb.public_host' => null,
        'broadcasting.connections.reverb.public_port' => null,
        'broadcasting.connections.reverb.public_scheme' => null,
        'broadcasting.connections.reverb.options.port' => 8080,
        'broadcasting.connections.reverb.options.scheme' => 'https',
    ]);

    $header = (string) $this->get(route('home'))->headers->get('Content-Security-Policy');

    expect($header)->toContain("connect-src 'self' blob: wss://chat.example.test:8080");
});

test('connect-src falls back to self when no websocket origin resolves', function (): void {
    config([
        'app.url' => '',
        'broadcasting.connections.reverb.public_host' => null,
    ]);

    $header = (string) $this->get(route('home'))->headers->get('Content-Security-Policy');

    expect($header)->toContain("connect-src 'self' blob:;");
});

test('the policy is not sent when csp is disabled', function (): void {
    config(['csp.enabled' => false]);

    $response = $this->get(route('home'))->assertOk();

    expect($response->headers->get('Content-Security-Policy'))->toBeNull();
    expect($response->headers->get('Content-Security-Policy-Report-Only'))->toBeNull();
});

test('report-only mode sends the report-only header and nothing to enforce', function (): void {
    config(['csp.report_only' => true]);

    $response = $this->get(route('home'))->assertOk();

    expect($response->headers->get('Content-Security-Policy'))->toBeNull();
    expect($response->headers->get('Content-Security-Policy-Report-Only'))
        ->toContain("default-src 'self'");
});

test('each extra source is appended without dropping our defaults', function (string $key, string $directive, string $value): void {
    config(["csp.extra.{$key}" => $value]);

    $header = (string) $this->get(route('home'))->headers->get('Content-Security-Policy');

    expect($header)
        ->toContain($directive)
        ->toContain($value)
        ->toContain("'strict-dynamic'")
        ->toMatch("/'nonce-[A-Za-z0-9+\/=_-]+'/");
})->with([
    'script' => ['script-src', 'script-src', 'https://analytics.example.test'],
    'style' => ['style-src', 'style-src', 'https://styles.example.test'],
    'image' => ['img-src', 'img-src', 'https://images.example.test'],
    'connect' => ['connect-src', 'connect-src', 'https://api.example.test'],
    'frame' => ['frame-src', 'frame-src', 'https://embed.example.test'],
    'font' => ['font-src', 'font-src', 'https://fonts.example.test'],
]);

test('allow-listing an external font pairing takes both the stylesheet host and the font host', function (): void {
    config([
        'csp.extra.style-src' => 'https://fonts.googleapis.test',
        'csp.extra.font-src' => 'https://fonts.gstatic.test',
    ]);

    $header = (string) $this->get(route('home'))->headers->get('Content-Security-Policy');

    expect($header)
        ->toContain("style-src 'self' 'unsafe-inline' https://fonts.googleapis.test")
        ->toContain("font-src 'self' https://fonts.gstatic.test");
});

test('a comma-separated extra list allow-lists every origin in it', function (): void {
    config(['csp.extra.img-src' => 'https://one.example.test, https://two.example.test']);

    $header = (string) $this->get(route('home'))->headers->get('Content-Security-Policy');

    expect($header)
        ->toContain('https://one.example.test')
        ->toContain('https://two.example.test');
});

test('an extra frame source replaces the none placeholder rather than sitting beside it', function (): void {
    config(['csp.extra.frame-src' => 'https://embed.example.test']);

    $header = (string) $this->get(route('home'))->headers->get('Content-Security-Policy');

    expect($header)->toContain('frame-src https://embed.example.test')
        ->not->toContain("frame-src 'none'");
});

test('an ambient vite dev server does not leak into the policy', function (): void {
    // `npm run dev` writes public/hot, which Vite reads straight off the
    // filesystem — so without the pin in beforeEach a dev server running in
    // another terminal splices its origin into script-src/style-src/connect-src
    // for every test in this file, breaking the ones that assert an exact
    // directive. Swap in a public path that carries a hot file to stand in for
    // that dev server, rather than writing to the real one it owns.
    $publicPath = sys_get_temp_dir().'/'.uniqid('the-desk-csp-public-', true);
    mkdir($publicPath);
    file_put_contents($publicPath.'/hot', "http://localhost:5173\n");
    $this->app->usePublicPath($publicPath);

    try {
        $contents = Policy::create([TheDeskPolicy::class])->getContents();
    } finally {
        unlink($publicPath.'/hot');
        rmdir($publicPath);
    }

    expect($contents)->not->toContain('localhost:5173');
});

test('hot mode allow-lists the vite dev server so npm run dev keeps working', function (): void {
    $hotFile = tempnam(sys_get_temp_dir(), 'vite-hot-');
    file_put_contents($hotFile, "http://localhost:5173\n");
    Vite::useHotFile($hotFile);

    try {
        $contents = Policy::create([TheDeskPolicy::class])->getContents();
    } finally {
        unlink($hotFile);
    }

    expect($contents)
        ->toContain('script-src')
        ->toContain('http://localhost:5173')
        ->toContain('ws://localhost:5173');
});

test('hot mode allow-lists the vite dev server as a font source so dev renders the real typography', function (): void {
    $hotFile = tempnam(sys_get_temp_dir(), 'vite-hot-');
    file_put_contents($hotFile, "http://localhost:5173\n");
    Vite::useHotFile($hotFile);

    try {
        $contents = Policy::create([TheDeskPolicy::class])->getContents();
    } finally {
        unlink($hotFile);
    }

    expect($contents)->toContain("font-src 'self' http://localhost:5173");
});
