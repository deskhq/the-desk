<?php

use App\Exceptions\Sso\InvalidIdTokenException;
use App\Services\Sso\GenericOidcProvider;
use Firebase\JWT\SignatureInvalidException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;

/**
 * The token response an IdP returns, carrying the given id_token alongside the
 * access token used to call UserInfo.
 */
function oidcTokenResponse(string $idToken): Response
{
    return new Response(200, [], (string) json_encode([
        'access_token' => 'access-123',
        'id_token' => $idToken,
        'expires_in' => 3600,
    ]));
}

/**
 * The UserInfo response for the fixed test subject.
 */
function oidcUserInfoResponse(string $sub = 'subject-999'): Response
{
    return new Response(200, [], (string) json_encode([
        'sub' => $sub,
        'name' => 'Dana Fox',
        'email' => 'dana@example.com',
    ]));
}

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

test('a valid id_token whose subject matches userinfo is accepted', function (): void {
    [$privatePem, $jwks, $kid] = oidcSigningKey();
    $idToken = oidcIdToken(['sub' => 'subject-999'], $privatePem, $kid);

    $provider = oidcProvider(new MockHandler([
        oidcDiscoveryResponse(),
        oidcTokenResponse($idToken),
        oidcUserInfoResponse('subject-999'),
        new Response(200, [], (string) json_encode($jwks)),
    ]));

    request()->merge(['code' => 'auth-code']);

    expect($provider->stateless()->user()->getId())->toBe('subject-999');
});

test('an id_token whose subject disagrees with userinfo is rejected', function (): void {
    [$privatePem, $jwks, $kid] = oidcSigningKey();
    // A well-formed, correctly signed token — but minted for a different subject
    // than the one UserInfo reports: the exact compromise this guards against.
    $idToken = oidcIdToken(['sub' => 'attacker-subject'], $privatePem, $kid);

    $provider = oidcProvider(new MockHandler([
        oidcDiscoveryResponse(),
        oidcTokenResponse($idToken),
        oidcUserInfoResponse('subject-999'),
        new Response(200, [], (string) json_encode($jwks)),
    ]));

    request()->merge(['code' => 'auth-code']);

    $provider->stateless()->user();
})->throws(InvalidIdTokenException::class);

test('an id_token from a foreign issuer is rejected', function (): void {
    [$privatePem, $jwks, $kid] = oidcSigningKey();
    $idToken = oidcIdToken(['iss' => 'https://evil.test'], $privatePem, $kid);

    $provider = oidcProvider(new MockHandler([
        oidcDiscoveryResponse(),
        oidcTokenResponse($idToken),
        oidcUserInfoResponse(),
        new Response(200, [], (string) json_encode($jwks)),
    ]));

    request()->merge(['code' => 'auth-code']);

    $provider->stateless()->user();
})->throws(InvalidIdTokenException::class);

test('an id_token is rejected when discovery publishes no issuer to verify against', function (): void {
    [$privatePem, $jwks, $kid] = oidcSigningKey();
    $idToken = oidcIdToken([], $privatePem, $kid);

    // A discovery document missing the required `issuer` field: the id_token's
    // issuer cannot be verified, so validation must fail closed rather than skip.
    $discoveryWithoutIssuer = new Response(200, [], (string) json_encode([
        'authorization_endpoint' => 'https://idp.test/authorize',
        'token_endpoint' => 'https://idp.test/token',
        'userinfo_endpoint' => 'https://idp.test/userinfo',
        'jwks_uri' => 'https://idp.test/jwks',
    ]));

    $provider = oidcProvider(new MockHandler([
        $discoveryWithoutIssuer,
        oidcTokenResponse($idToken),
        oidcUserInfoResponse(),
        new Response(200, [], (string) json_encode($jwks)),
    ]));

    request()->merge(['code' => 'auth-code']);

    $provider->stateless()->user();
})->throws(InvalidIdTokenException::class);

test('an id_token minted for another audience is rejected', function (): void {
    [$privatePem, $jwks, $kid] = oidcSigningKey();
    $idToken = oidcIdToken(['aud' => 'some-other-client'], $privatePem, $kid);

    $provider = oidcProvider(new MockHandler([
        oidcDiscoveryResponse(),
        oidcTokenResponse($idToken),
        oidcUserInfoResponse(),
        new Response(200, [], (string) json_encode($jwks)),
    ]));

    request()->merge(['code' => 'auth-code']);

    $provider->stateless()->user();
})->throws(InvalidIdTokenException::class);

test('an id_token not signed by the provider key is rejected', function (): void {
    [, $jwks, $kid] = oidcSigningKey();
    [$attackerPem] = oidcSigningKey();
    // Signed with a key the provider's JWKS does not vouch for.
    $idToken = oidcIdToken([], $attackerPem, $kid);

    $provider = oidcProvider(new MockHandler([
        oidcDiscoveryResponse(),
        oidcTokenResponse($idToken),
        oidcUserInfoResponse(),
        new Response(200, [], (string) json_encode($jwks)),
    ]));

    request()->merge(['code' => 'auth-code']);

    $provider->stateless()->user();
})->throws(SignatureInvalidException::class);

test('id_token validation is skipped when the token response omits it', function (): void {
    $provider = oidcProvider(new MockHandler([
        oidcDiscoveryResponse(),
        new Response(200, [], (string) json_encode(['access_token' => 'access-123', 'expires_in' => 3600])),
        oidcUserInfoResponse('subject-999'),
    ]));

    request()->merge(['code' => 'auth-code']);

    expect($provider->stateless()->user()->getId())->toBe('subject-999');
});

test('id_token validation can be turned off, trusting userinfo alone', function (): void {
    config(['sso.oidc.validate_id_token' => false]);

    [$privatePem] = oidcSigningKey();
    // A token that would fail validation is ignored entirely when disabled.
    $idToken = oidcIdToken(['sub' => 'attacker-subject'], $privatePem);

    $provider = oidcProvider(new MockHandler([
        oidcDiscoveryResponse(),
        oidcTokenResponse($idToken),
        oidcUserInfoResponse('subject-999'),
    ]));

    request()->merge(['code' => 'auth-code']);

    expect($provider->stateless()->user()->getId())->toBe('subject-999');
});
