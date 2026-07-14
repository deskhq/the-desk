<?php

use App\Services\Sso\GenericOidcProvider;
use GuzzleHttp\Handler\MockHandler;
use Laravel\Socialite\Facades\Socialite;

test('the oidc driver is built from services config with default scopes and a derived discovery url', function (): void {
    $mock = new MockHandler([oidcDiscoveryResponse()]);
    config(['services.oidc' => oidcServicesConfig($mock)]);
    Socialite::forgetDrivers();

    /** @var GenericOidcProvider $provider */
    $provider = Socialite::driver('oidc');

    expect($provider)->toBeInstanceOf(GenericOidcProvider::class)
        ->and($provider->getScopes())->toBe(['openid', 'profile', 'email']);

    // Driving the redirect exercises the whole registration closure: buildProvider
    // wired the client id + redirect, and the discovery URL was derived from the
    // issuer as {issuer}/.well-known/openid-configuration.
    $url = $provider->stateless()->redirect()->getTargetUrl();

    expect((string) $mock->getLastRequest()->getUri())
        ->toBe('https://idp.test/.well-known/openid-configuration');
    expect($url)->toStartWith('https://idp.test/authorize?')
        ->toContain('client_id=client-id')
        ->toContain('redirect_uri=https%3A%2F%2Fapp.test%2Fauth%2Foidc%2Fcallback');
});

test('the oidc driver honors an explicit discovery url and custom scopes', function (): void {
    $mock = new MockHandler([oidcDiscoveryResponse()]);
    config(['services.oidc' => oidcServicesConfig($mock, [
        'discovery_url' => 'https://idp.test/custom/openid-configuration',
        'scopes' => 'openid email',
    ])]);
    Socialite::forgetDrivers();

    /** @var GenericOidcProvider $provider */
    $provider = Socialite::driver('oidc');

    expect($provider->getScopes())->toBe(['openid', 'email']);

    $url = $provider->stateless()->redirect()->getTargetUrl();

    // The explicit discovery URL was fetched, not the derived well-known path.
    expect((string) $mock->getLastRequest()->getUri())
        ->toBe('https://idp.test/custom/openid-configuration');
    expect($url)->toStartWith('https://idp.test/authorize?')
        ->toContain('scope=openid+email');
});
