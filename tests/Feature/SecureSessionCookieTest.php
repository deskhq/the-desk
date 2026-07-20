<?php

declare(strict_types=1);

use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;

function sessionCookie(TestResponse $response): Cookie
{
    $name = config('session.cookie');

    /** @var Cookie|null $cookie */
    $cookie = collect($response->headers->getCookies())->first(
        fn (Cookie $cookie): bool => $cookie->getName() === $name,
    );

    expect($cookie)->not->toBeNull();

    return $cookie;
}

test('the session cookie is withheld from plain http once the flag is on', function (): void {
    config(['session.secure' => true]);

    expect(sessionCookie($this->get('https://localhost/'))->isSecure())->toBeTrue();
});

test('the flag can be turned off for a deployment that never speaks https', function (): void {
    config(['session.secure' => false]);

    expect(sessionCookie($this->get('https://localhost/'))->isSecure())->toBeFalse();
});
