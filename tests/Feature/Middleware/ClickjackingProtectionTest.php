<?php

declare(strict_types=1);

beforeEach(function (): void {
    // .env.example turns report-only on for local development, so every test
    // states the posture it is asserting rather than inheriting the ambient one.
    config([
        'csp.enabled' => true,
        'csp.report_only' => false,
        'csp.frame_ancestors' => 'none',
    ]);
});

test('web responses deny framing outright by default', function (): void {
    $response = $this->get(route('home'))->assertOk();

    expect($response->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'none'");
    expect($response->headers->get('X-Frame-Options'))->toBe('DENY');
});

test('same-origin framing is allowed when the operator asks for it', function (): void {
    config(['csp.frame_ancestors' => 'self']);

    $response = $this->get(route('home'))->assertOk();

    expect($response->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'self'");
    expect($response->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN');
});

test('the keyword is recognised however the operator cased or quoted it', function (string $value, string $expected): void {
    config(['csp.frame_ancestors' => $value]);

    $header = (string) $this->get(route('home'))->headers->get('Content-Security-Policy');

    expect($header)->toContain("frame-ancestors {$expected}");
})->with([
    'upper-case none' => ['NONE', "'none'"],
    'upper-case self' => ['Self', "'self'"],
    'pre-quoted none' => ["'none'", "'none'"],
    'pre-quoted self' => ["'self'", "'self'"],
]);

test('an operator can allow-list the portal that embeds the app', function (): void {
    config(['csp.frame_ancestors' => 'https://portal.example.test']);

    $response = $this->get(route('home'))->assertOk();

    expect($response->headers->get('Content-Security-Policy'))
        ->toContain('frame-ancestors https://portal.example.test');

    // X-Frame-Options cannot express an allow-list — its ALLOW-FROM was never
    // supported by Chrome and was dropped by Firefox — so sending anything here
    // would either break the embed (DENY) or lie about it.
    expect($response->headers->get('X-Frame-Options'))->toBeNull();
});

test('a comma-separated ancestor list allow-lists every origin in it', function (): void {
    config(['csp.frame_ancestors' => 'https://one.example.test, https://two.example.test']);

    $header = (string) $this->get(route('home'))->headers->get('Content-Security-Policy');

    expect($header)->toContain('frame-ancestors https://one.example.test https://two.example.test');
});

test('a blank ancestor list falls back to denying every framer', function (): void {
    config(['csp.frame_ancestors' => '']);

    $response = $this->get(route('home'))->assertOk();

    expect($response->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'none'");
    expect($response->headers->get('X-Frame-Options'))->toBe('DENY');
});

test('none beside an origin fails closed rather than silently allowing the origin', function (): void {
    config(['csp.frame_ancestors' => 'none, https://portal.example.test']);

    $response = $this->get(route('home'))->assertOk();

    // A contradictory list is a typo, and the safe reading of a typo in an
    // anti-clickjacking control is "deny" — a blocked embed is a visible bug,
    // an accidentally framable app is not.
    expect($response->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'none'");
    expect($response->headers->get('X-Frame-Options'))->toBe('DENY');
});

test('neither header is sent when the app policy is switched off', function (): void {
    config(['csp.enabled' => false]);

    $response = $this->get(route('home'))->assertOk();

    expect($response->headers->get('Content-Security-Policy'))->toBeNull();
    expect($response->headers->get('X-Frame-Options'))->toBeNull();
});

test('report-only mode reports the ancestors without enforcing them', function (): void {
    config(['csp.report_only' => true]);

    $response = $this->get(route('home'))->assertOk();

    expect($response->headers->get('Content-Security-Policy'))->toBeNull();
    expect($response->headers->get('Content-Security-Policy-Report-Only'))
        ->toContain("frame-ancestors 'none'");

    // X-Frame-Options has no report-only form, so emitting it would enforce the
    // very thing the dry run is meant to only observe.
    expect($response->headers->get('X-Frame-Options'))->toBeNull();
});

test('api responses carry no framing headers — they are never rendered in a frame', function (): void {
    $response = $this->getJson('/api/v1/channels');

    expect($response->headers->get('Content-Security-Policy'))->toBeNull();
    expect($response->headers->get('X-Frame-Options'))->toBeNull();
});
