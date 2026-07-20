<?php

declare(strict_types=1);

beforeEach(function (): void {
    config([
        'security.hsts.enabled' => true,
        'security.hsts.max_age' => 31536000,
        'security.hsts.include_subdomains' => true,
        'security.hsts.preload' => false,
    ]);
});

test('a secure response pins the host to https for a year, subdomains included', function (): void {
    $response = $this->get('https://localhost/')->assertOk();

    expect($response->headers->get('Strict-Transport-Security'))
        ->toBe('max-age=31536000; includeSubDomains');
});

test('a plain-http response carries no policy at all', function (): void {
    // Emitting it here would let a LAN deployment lock itself out of its own
    // hostname: the browser would refuse the only scheme the host answers on.
    $response = $this->get('http://localhost/')->assertOk();

    expect($response->headers->get('Strict-Transport-Security'))->toBeNull();
});

test('an operator can shorten the max-age while rolling the policy out', function (): void {
    config(['security.hsts.max_age' => 300]);

    expect($this->get('https://localhost/')->headers->get('Strict-Transport-Security'))
        ->toBe('max-age=300; includeSubDomains');
});

test('a negative max-age is floored at zero rather than emitted verbatim', function (): void {
    config(['security.hsts.max_age' => -1]);

    // max-age=0 is the standard "forget this host" value; a negative one is a
    // typo the browser would reject, taking the whole header with it.
    expect($this->get('https://localhost/')->headers->get('Strict-Transport-Security'))
        ->toBe('max-age=0; includeSubDomains');
});

test('subdomains can be left out for a host that serves some of them over http', function (): void {
    config(['security.hsts.include_subdomains' => false]);

    expect($this->get('https://localhost/')->headers->get('Strict-Transport-Security'))
        ->toBe('max-age=31536000');
});

test('preload is opt-in only — it is effectively irreversible for a domain', function (): void {
    config(['security.hsts.preload' => true]);

    expect($this->get('https://localhost/')->headers->get('Strict-Transport-Security'))
        ->toBe('max-age=31536000; includeSubDomains; preload');
});

test('preload is withheld when the rest of the policy would not qualify for the list', function (array $overrides): void {
    config(['security.hsts.preload' => true, ...$overrides]);

    // hstspreload.org rejects a submission whose max-age is under a year or
    // whose subdomains are excluded. Advertising `preload` anyway states an
    // intent the policy cannot back, so send the directives that are true.
    expect($this->get('https://localhost/')->headers->get('Strict-Transport-Security'))
        ->not->toContain('preload');
})->with([
    'max-age under a year' => [['security.hsts.max_age' => 300]],
    'subdomains excluded' => [['security.hsts.include_subdomains' => false]],
]);

test('an error response is pinned too — it is the same connection', function (): void {
    // The pin has to survive the paths a visitor hits by accident, or a browser
    // that only ever saw a 404 stays willing to downgrade.
    $response = $this->get('https://localhost/definitely-not-a-route')->assertNotFound();

    expect($response->headers->get('Strict-Transport-Security'))
        ->toBe('max-age=31536000; includeSubDomains');
});

test('the health check is pinned too — it answers before any route', function (): void {
    $response = $this->get('https://localhost/up')->assertOk();

    expect($response->headers->get('Strict-Transport-Security'))
        ->toBe('max-age=31536000; includeSubDomains');
});

test('the header is dropped entirely when the operator owns it at the proxy', function (): void {
    config(['security.hsts.enabled' => false]);

    expect($this->get('https://localhost/')->headers->get('Strict-Transport-Security'))->toBeNull();
});

test('api responses are pinned too — the transport is the same one', function (): void {
    $response = $this->getJson('https://localhost/api/v1/channels');

    expect($response->headers->get('Strict-Transport-Security'))
        ->toBe('max-age=31536000; includeSubDomains');
});
