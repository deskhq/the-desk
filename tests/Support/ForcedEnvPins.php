<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use SimpleXMLElement;

/**
 * Makes phpunit.xml's `force="true"` env pins beat an exported value.
 *
 * PHPUnit's PhpHandler writes a forced pin to putenv() and $_ENV, but never to
 * $_SERVER. An exported variable (`export`, `docker run -e`, `sail exec -e`)
 * lands in $_SERVER too, and Laravel's env repository reads that adapter first,
 * so the export would still win over the pin. Clearing the $_SERVER copy of
 * every forced name lets the pin resolve, whichever way the value arrived.
 */
final class ForcedEnvPins
{
    /**
     * Drop the $_SERVER copy of every force-pinned name, so the pin resolves.
     *
     * Call this from the bootstrap script: PHPUnit applies the `<php>` pins
     * before it loads the bootstrap, so the pinned values are already sitting
     * in putenv()/$_ENV by the time the stale $_SERVER entries go away.
     */
    public static function clearServerCopies(string $configurationPath): void
    {
        foreach (self::names($configurationPath) as $name) {
            unset($_SERVER[$name]);
        }
    }

    /**
     * The names of the `force="true"` env pins declared in a PHPUnit configuration.
     *
     * @return list<string>
     */
    public static function names(string $configurationPath): array
    {
        $configuration = @simplexml_load_file($configurationPath);

        throw_unless($configuration instanceof SimpleXMLElement, RuntimeException::class, "Unable to read the PHPUnit configuration at [{$configurationPath}].");

        $names = [];

        foreach ($configuration->xpath('//php/env') ?: [] as $pin) {
            if (((string) ($pin['force'] ?? '')) === 'true') {
                $names[] = (string) $pin['name'];
            }
        }

        return $names;
    }
}
