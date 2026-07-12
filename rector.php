<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Class_\InlineConstructorDefaultToPropertyRector;
use Rector\Config\RectorConfig;
use RectorLaravel\Rector\ArrayDimFetch\EnvVariableToEnvHelperRector;
use RectorLaravel\Rector\FuncCall\AppToResolveRector;
use RectorLaravel\Rector\StaticCall\CarbonToDateFacadeRector;
use RectorLaravel\Set\LaravelSetList;

return RectorConfig::configure()
    // Mirror the paths analyzed by PHPStan (see phpstan.neon), plus tests/.
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/bootstrap/app.php',
        __DIR__.'/config',
        __DIR__.'/database',
        __DIR__.'/routes',
        __DIR__.'/tests',
    ])
    // Apply the PHP level set matching the version declared in composer.json.
    ->withPhpSets()
    // Import namespaced class references instead of emitting redundant
    // fully-qualified names when a `use` statement already exists. Global
    // short classes (e.g. `\Override`) stay qualified as PHP requires.
    ->withImportNames(importShortClasses: false)
    // Conservative, high-signal rule sets. Structural refactors that complement
    // Pint (style) and PHPStan (detection) without changing behaviour.
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        earlyReturn: true,
    )
    // Laravel-aware refactors that modernise framework idioms safely.
    ->withSets([
        LaravelSetList::LARAVEL_CODE_QUALITY,
        LaravelSetList::LARAVEL_IF_HELPERS,
    ])
    // Opt out of rules that are noisy or conflict with our conventions. Revisit
    // and trim this list as the codebase adopts more of Rector's suggestions.
    ->withSkip([
        // Keep explicit constructor bodies readable; do not inline defaults.
        InlineConstructorDefaultToPropertyRector::class,
        // `app()` and `resolve()` are equivalent; don't churn the codebase over
        // a purely stylistic preference.
        AppToResolveRector::class,
        // The `Date` facade resolves to CarbonImmutable under Larastan, which
        // clashes with our explicit `Illuminate\Support\Carbon` type hints.
        CarbonToDateFacadeRector::class,
        // Unsafe in write contexts: rewrites `unset($_ENV[...])` into
        // `unset(Env::get(...))`, which is not valid PHP.
        EnvVariableToEnvHelperRector::class,
    ]);
