<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/**
 * A PaaS that fronts compose services with an injected proxy network (Dokploy,
 * Coolify, CapRover) rewrites `networks:` on whichever service a domain is
 * attached to. A custom bridge network therefore splits the stack in half: the
 * proxied service moves to the injected network while the rest stay behind, and
 * the web container crash-loops on `could not translate host name "pgsql"`.
 * The implicit `default` network is where the injection lands anyway, so the
 * custom one buys nothing. See issue #751.
 */
$prod = fn (): array => Yaml::parseFile(dirname(__DIR__, 2).'/docker-compose.prod.yml');

$dokploy = fn (): array => Yaml::parseFile(dirname(__DIR__, 2).'/docker-compose.dokploy.yml');

/** The services Traefik routes to, and the only two that publish host ports. */
$proxied = ['app', 'reverb'];

test('the production stack declares no custom network', function () use ($prod): void {
    expect($prod())->not->toHaveKey('networks');
});

test('no production service pins itself to a network', function () use ($prod): void {
    $pinned = array_keys(array_filter($prod()['services'], static fn (array $service): bool => isset($service['networks'])));

    expect($pinned)->toBe([]);
});

test('the dokploy template covers exactly the production services', function () use ($prod, $dokploy): void {
    expect(array_keys($dokploy()['services']))->toBe(array_keys($prod()['services']));
});

/**
 * The template is a standalone file because Dokploy points at a single compose
 * path, so it can only stay correct by being a mechanical mirror: the production
 * stack minus host publishing, plus the proxy network. Anything else that
 * diverges is drift, and this is what catches it.
 */
test('the dokploy template is the production stack minus host publishing', function (string $name) use ($prod, $dokploy, $proxied): void {
    $expected = $prod()['services'][$name];
    unset($expected['ports']);

    if (in_array($name, $proxied, true)) {
        $expected['networks'] = ['default', 'dokploy-network'];
    }

    expect($dokploy()['services'][$name])->toEqual($expected);
})->with(fn (): array => array_keys(Yaml::parseFile(dirname(__DIR__, 2).'/docker-compose.prod.yml')['services']));

test('the dokploy template joins the proxy network explicitly', function () use ($dokploy): void {
    expect($dokploy()['networks'])->toBe(['dokploy-network' => ['external' => true]]);
});

test('the dokploy template keeps the production volumes', function () use ($prod, $dokploy): void {
    expect($dokploy()['volumes'])->toEqual($prod()['volumes']);
});
