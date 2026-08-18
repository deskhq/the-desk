<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * The production compose stack, and the services in it that run the shared
 * application image.
 *
 * That set is derived from the file rather than written down, because every
 * guard over the stack — the branding bind mount, the shared `storage-app`
 * volume, the build overlay — has to agree on it. Deriving it means a sixth
 * app-role service joins the set on its own and fails whichever guard it is
 * missing from, instead of drifting unnoticed until a release trips over it
 * (see issue #1040).
 */
final class ProductionCompose
{
    /**
     * The registry reference every app-role service resolves to by default.
     */
    public const string APP_IMAGE = 'ghcr.io/deskhq/the-desk';

    /**
     * The parsed production stack.
     *
     * @return array<string, mixed>
     */
    public static function stack(): array
    {
        return self::parse('docker-compose.prod.yml');
    }

    /**
     * The parsed build-from-source overlay.
     *
     * @return array<string, mixed>
     */
    public static function buildOverlay(): array
    {
        return self::parse('docker-compose.build.yml');
    }

    /**
     * Every production service, keyed by name.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function services(): array
    {
        /** @var array<string, array<string, mixed>> $services */
        $services = self::stack()['services'];

        return $services;
    }

    /**
     * The services that run the shared application image.
     *
     * Every one of these needs a local `build:` in the build-from-source
     * overlay, or it is pulled from the registry while its siblings are built
     * (#1040). That is the only thing this set means.
     *
     * @return list<string>
     */
    public static function sharedImageServices(): array
    {
        $services = array_values(array_keys(array_filter(
            self::services(),
            static fn (array $service): bool => self::runsAppImage(self::imageOf($service)),
        )));

        // Fail closed. Every guard over the stack drives its cases off this set,
        // so a production `image:` the match no longer recognises would pass all
        // of them vacuously — the one failure the derivation cannot report by
        // returning a value.
        throw_unless(
            in_array('app', $services, true) && count($services) > 1,
            RuntimeException::class,
            'No shared-image services derived from docker-compose.prod.yml — does it still run ['.self::APP_IMAGE.']?',
        );

        return $services;
    }

    /**
     * The shared-image services that run the Laravel application itself.
     *
     * These two sets used to be one, and came apart when the unfurler arrived
     * (ADR-0016): it ships in the same image but runs a Go binary against it, so
     * it needs the build overlay like its siblings while mounting no application
     * storage, taking no branding and reading no `.env`. Giving that container
     * the application's environment is most of what isolating it was for.
     *
     * The distinction is drawn on `entrypoint:`, which is what a service
     * overrides precisely when it is not running the application.
     *
     * @return list<string>
     */
    public static function laravelServices(): array
    {
        $services = array_values(array_filter(
            self::sharedImageServices(),
            static fn (string $name): bool => ! isset(self::services()[$name]['entrypoint']),
        ));

        // Fail closed again, and for a second reason: if `entrypoint:` ever
        // became the norm rather than the exception this would quietly empty out
        // and every storage, branding and dependency guard would pass on nothing.
        throw_unless(
            in_array('app', $services, true) && count($services) > 1,
            RuntimeException::class,
            'No Laravel services derived from docker-compose.prod.yml — has the entrypoint convention changed?',
        );

        return $services;
    }

    /**
     * The Laravel services other than `app` itself — the workers that wait for
     * it before they start.
     *
     * @return list<string>
     */
    public static function laravelWorkers(): array
    {
        return array_values(array_filter(
            self::laravelServices(),
            static fn (string $service): bool => $service !== 'app',
        ));
    }

    /**
     * A service's image reference, or an empty string when it declares none.
     *
     * @param  array<string, mixed>  $service
     */
    public static function imageOf(array $service): string
    {
        return is_string($service['image'] ?? null) ? $service['image'] : '';
    }

    /**
     * Whether an image reference runs the shared application image.
     *
     * Matched up to the tag or digest separator, so a sibling repository whose
     * name merely starts with it is not swept in as an app-role service.
     */
    private static function runsAppImage(string $image): bool
    {
        return preg_match('#'.preg_quote(self::APP_IMAGE, '#').'[:@]#', $image) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    private static function parse(string $file): array
    {
        /** @var array<string, mixed> $parsed */
        $parsed = Yaml::parseFile(dirname(__DIR__, 2).'/'.$file);

        return $parsed;
    }
}
