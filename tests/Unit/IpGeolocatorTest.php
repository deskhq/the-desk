<?php

declare(strict_types=1);

use App\Support\IpGeolocator;

/**
 * The bundled MaxMind test database ships known fixture ranges, so these
 * assertions are deterministic without any network access.
 */
function geolocator(): IpGeolocator
{
    return new IpGeolocator(dirname(__DIR__).'/Fixtures/geoip/GeoLite2-City-Test.mmdb');
}

test('it resolves a known IPv4 address to a city and country', function (): void {
    expect(geolocator()->locate('81.2.69.160'))->toBe('London, GB');
});

test('it resolves a known IPv6 address, falling back to the country when the city is unknown', function (): void {
    expect(geolocator()->locate('2001:218:85a3::8a2e:370:7334'))->toBe('JP');
});

test('it returns null for a public address the database does not know', function (): void {
    expect(geolocator()->locate('8.8.8.8'))->toBeNull();
});

test('it returns null for private and reserved ranges without hitting the database', function (string $ip): void {
    expect(geolocator()->locate($ip))->toBeNull();
})->with([
    'private IPv4' => ['192.168.1.1'],
    'loopback IPv4' => ['127.0.0.1'],
    'link-local IPv6' => ['fe80::1'],
    'unique-local IPv6' => ['fd00::1'],
]);

test('it returns null for an invalid address', function (): void {
    expect(geolocator()->locate('not-an-ip'))->toBeNull();
});

test('it returns null for a null address', function (): void {
    expect(geolocator()->locate(null))->toBeNull();
});

test('it returns null when the database file is missing', function (): void {
    $geolocator = new IpGeolocator(dirname(__DIR__).'/Fixtures/geoip/does-not-exist.mmdb');

    expect($geolocator->locate('81.2.69.160'))->toBeNull();
});

test('it returns null when pointed at a non-City database', function (): void {
    $geolocator = new IpGeolocator(dirname(__DIR__).'/Fixtures/geoip/GeoLite2-Country-Test.mmdb');

    expect($geolocator->locate('81.2.69.160'))->toBeNull();
});

test('it returns null when the database file is unreadable', function (): void {
    $path = (string) tempnam(sys_get_temp_dir(), 'geoip');
    file_put_contents($path, 'not a valid database');

    try {
        expect((new IpGeolocator($path))->locate('81.2.69.160'))->toBeNull();
    } finally {
        @unlink($path);
    }
});
