<?php

use App\Services\Sso\GenericOidcProvider;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Build the provider wired to a Guzzle mock handler so the OIDC discovery,
 * token, and userinfo endpoints are served offline in the order they're called.
 */
function oidcProvider(MockHandler $mock): GenericOidcProvider
{
    Cache::flush();

    $provider = new GenericOidcProvider(
        request(),
        'client-id',
        'client-secret',
        'https://app.test/auth/oidc/callback',
        ['handler' => HandlerStack::create($mock)],
    );

    return $provider->setDiscoveryUrl('https://idp.test/.well-known/openid-configuration');
}

test('the redirect url is built from the discovery document', function (): void {
    $provider = oidcProvider(new MockHandler([oidcDiscoveryResponse()]));

    $url = $provider->stateless()->redirect()->getTargetUrl();

    expect($url)->toStartWith('https://idp.test/authorize?')
        ->toContain('client_id=client-id')
        ->toContain('scope=openid+profile+email')
        ->toContain('response_type=code');
});

test('the callback resolves the user from the token and userinfo endpoints', function (): void {
    $provider = oidcProvider(new MockHandler([
        oidcDiscoveryResponse(),
        new Response(200, [], (string) json_encode(['access_token' => 'access-123', 'expires_in' => 3600])),
        new Response(200, [], (string) json_encode([
            'sub' => 'subject-999',
            'name' => 'Dana Fox',
            'email' => 'dana@example.com',
            'picture' => 'https://idp.test/dana.png',
        ])),
    ]));

    request()->merge(['code' => 'auth-code']);

    $user = $provider->stateless()->user();

    expect($user->getId())->toBe('subject-999')
        ->and($user->getName())->toBe('Dana Fox')
        ->and($user->getEmail())->toBe('dana@example.com')
        ->and($user->getAvatar())->toBe('https://idp.test/dana.png')
        ->and($user->token)->toBe('access-123');
});
