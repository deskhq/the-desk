<?php

use App\Support\HostResolver;

test('a literal IP resolves to itself', function () {
    expect((new HostResolver)->resolve('93.184.216.34'))->toBe(['93.184.216.34']);
});

test('a resolvable hostname resolves to its addresses', function () {
    // localhost resolves from the hosts file, so this needs no live DNS.
    expect((new HostResolver)->resolve('localhost'))->toContain('127.0.0.1');
});

test('an unresolvable hostname resolves to no addresses', function () {
    // The .invalid TLD is guaranteed never to resolve (RFC 6761).
    expect((new HostResolver)->resolve('nonexistent-host.invalid'))->toBe([]);
});
