<?php

use App\Support\UserAgentParser;

test('it detects the browser', function (string $userAgent, string $browser): void {
    expect(UserAgentParser::parse($userAgent)['browser'])->toBe($browser);
})->with([
    'Edge' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0', 'Edge'],
    'Opera' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 OPR/106.0.0.0', 'Opera'],
    'Firefox' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0', 'Firefox'],
    'Chrome' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36', 'Chrome'],
    'Safari' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Safari/605.1.15', 'Safari'],
    'unknown browser' => ['curl/8.4.0', 'Unknown browser'],
]);

test('it detects the platform', function (string $userAgent, string $platform): void {
    expect(UserAgentParser::parse($userAgent)['platform'])->toBe($platform);
})->with([
    'Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36', 'Windows'],
    'iOS' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_2 like Mac OS X) AppleWebKit/605.1.15 Version/17.2 Mobile/15E148 Safari/604.1', 'iOS'],
    'Android' => ['Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/120.0.0.0 Mobile Safari/537.36', 'Android'],
    'macOS' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Version/17.2 Safari/605.1.15', 'macOS'],
    'Linux' => ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36', 'Linux'],
    'unknown platform' => ['curl/8.4.0', 'Unknown platform'],
]);

test('it falls back to unknowns for a missing user agent', function (?string $userAgent): void {
    expect(UserAgentParser::parse($userAgent))->toBe([
        'browser' => 'Unknown browser',
        'platform' => 'Unknown platform',
    ]);
})->with([
    'null' => [null],
    'empty string' => [''],
]);
