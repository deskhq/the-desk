<?php

declare(strict_types=1);

use App\Ldap\DirectoryUser;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use LdapRecord\Laravel\Testing\DirectoryEmulator;
use LdapRecord\Testing\ConnectionFake;

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

/**
 * The env needed to wire up an LDAP directory: a host to reach and a base DN to
 * search under. Pass to reloadWithEnv() so config('sso.ldap.enabled') is true and
 * the Fortify directory-bind callback is registered when the app boots.
 *
 * @param  array<string, bool|int|string>  $overrides
 * @return array<string, bool|int|string>
 */
function ldapEnv(array $overrides = []): array
{
    return array_merge([
        'LDAP_HOST' => 'ldap.test',
        'LDAP_BASE_DN' => 'dc=the-desk,dc=local',
    ], $overrides);
}

/**
 * Stand up LdapRecord's in-memory directory emulator on the default connection,
 * so tests bind against a fake directory rather than a live server. Returns the
 * fake connection; call actingAs($entry) on it to let a given entry's bind pass.
 */
function fakeDirectory(): ConnectionFake
{
    return DirectoryEmulator::setup('default');
}

/**
 * Create a directory entry in the emulator.
 *
 * @param  array<string, mixed>  $attributes
 */
function directoryUser(array $attributes): DirectoryUser
{
    return DirectoryUser::create($attributes);
}
