<?php

declare(strict_types=1);

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

/**
 * A canned OIDC discovery document pointing at fake IdP endpoints, served to the
 * generic provider through a mocked Guzzle handler so no network is touched.
 */
function oidcDiscoveryResponse(): Response
{
    return new Response(200, [], (string) json_encode([
        'authorization_endpoint' => 'https://idp.test/authorize',
        'token_endpoint' => 'https://idp.test/token',
        'userinfo_endpoint' => 'https://idp.test/userinfo',
    ]));
}

/**
 * Build a `services.oidc` config array whose provider talks to the given mocked
 * Guzzle handler (queue discovery, then token/userinfo as needed) instead of a
 * real identity provider. The caller keeps the handler to inspect requests.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function oidcServicesConfig(MockHandler $mock, array $overrides = []): array
{
    return array_merge([
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'redirect' => 'https://app.test/auth/oidc/callback',
        'issuer' => 'https://idp.test',
        'discovery_url' => null,
        'scopes' => null,
        'guzzle' => ['handler' => HandlerStack::create($mock)],
    ], $overrides);
}
