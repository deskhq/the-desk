<?php

use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Locks the cookie posture a surface scan keeps re-raising (issue #600): the
 * session cookie is HttpOnly, `XSRF-TOKEN` is deliberately not, and the only
 * cookies exempt from encryption are the two UI-preference ones.
 */
function cookieFromResponse(TestResponse $response, string $name): Cookie
{
    $cookie = collect($response->headers->getCookies())
        ->first(fn (Cookie $cookie): bool => $cookie->getName() === $name);

    expect($cookie)->not->toBeNull("Expected the response to set a [{$name}] cookie.");

    return $cookie;
}

test('the session cookie is http-only and same-site lax', function (): void {
    $response = $this->get('/login');

    $cookie = cookieFromResponse($response, config('session.cookie'));

    expect($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getSameSite())->toBe(Cookie::SAMESITE_LAX);
});

test('the session cookie is marked secure when SESSION_SECURE_COOKIE is on', function (): void {
    expect(cookieFromResponse($this->get('/login'), config('session.cookie'))->isSecure())->toBeFalse();

    config()->set('session.secure', true);

    expect(cookieFromResponse($this->get('/login'), config('session.cookie'))->isSecure())->toBeTrue();
});

test('the XSRF-TOKEN cookie is readable by javascript by design, but encrypted and same-site lax', function (): void {
    $response = $this->get('/login');

    $cookie = cookieFromResponse($response, 'XSRF-TOKEN');

    // Not HttpOnly on purpose: the XHR client reads it to echo the token back
    // in the X-XSRF-TOKEN header, which is the whole CSRF mechanism.
    expect($cookie->isHttpOnly())->toBeFalse()
        ->and($cookie->getSameSite())->toBe(Cookie::SAMESITE_LAX)
        ->and(CookieValuePrefix::remove(Crypt::decrypt($cookie->getValue(), false)))->toBe(session()->token());
});

test('only the two non-sensitive ui preference cookies skip encryption', function (): void {
    $exempt = (new ReflectionClass(EncryptCookies::class))->getStaticPropertyValue('neverEncrypt');

    expect($exempt)->toEqualCanonicalizing(['appearance', 'sidebar_state']);
});

test('the exempt cookies are read as plain text', function (): void {
    $this->withUnencryptedCookies(['appearance' => 'dark', 'sidebar_state' => 'false'])
        ->get('/login')
        ->assertOk()
        ->assertSee('class="dark"', escape: false);
});
