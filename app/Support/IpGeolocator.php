<?php

declare(strict_types=1);

namespace App\Support;

use GeoIp2\Database\Reader;
use GeoIp2\Exception\AddressNotFoundException;
use GeoIp2\Model\City;
use InvalidArgumentException;
use MaxMind\Db\Reader\InvalidDatabaseException;

/**
 * Resolves an approximate, human-readable location for an IP address from a
 * local MaxMind GeoLite2 / GeoIP2 City database.
 *
 * The lookup is fully offline and best-effort: any address that is missing,
 * private, reserved, invalid, or unknown to the database (and any missing or
 * unreadable database) resolves to null, so callers can simply omit the
 * location when none is available.
 */
class IpGeolocator
{
    /**
     * The lazily-opened database reader, memoised after the first successful open.
     */
    private ?Reader $reader = null;

    public function __construct(private readonly string $databasePath) {}

    /**
     * Resolve a "City, CC" label (or a bare country code when the city is
     * unknown) for a public IP address, or null when it cannot be located.
     */
    public function locate(?string $ip): ?string
    {
        if ($ip === null || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return null;
        }

        if (! is_file($this->databasePath)) {
            return null;
        }

        try {
            return $this->format($this->reader()->city($ip));
        } catch (AddressNotFoundException|InvalidDatabaseException|InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Open (once) and return the database reader.
     */
    private function reader(): Reader
    {
        return $this->reader ??= new Reader($this->databasePath);
    }

    /**
     * Join the city name and country code into a display label, dropping any
     * segment the database left blank.
     */
    private function format(City $record): ?string
    {
        $parts = array_values(array_filter(
            [$record->city->name, $record->country->isoCode],
            static fn (?string $part): bool => $part !== null && $part !== '',
        ));

        return $parts === [] ? null : implode(', ', $parts);
    }
}
