<?php

declare(strict_types=1);

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Symfony\Component\Finder\Finder;

/**
 * The server-to-client contract is generated, not written twice.
 *
 * `spatie/laravel-typescript-transformer` emits every `#[TypeScript]` DTO in
 * `app/Data/` and every enum in `app/Enums/` into `App.Data.*` / `App.Enums.*`.
 * Before #1146, `resources/js/types/` hand-declared 31 types that restated one of
 * those field for field — prose on the client, truth on the server, and nothing
 * checking the two against each other. A DTO field rename was silent drift until
 * something rendered wrong.
 *
 * This test is what keeps the contract single. A hand-written type whose field set
 * is exactly a DTO's — or whose literal union is exactly an enum's cases — is a
 * shadow, and a shadow is a defect: alias the generated type instead. A type that
 * genuinely differs (a client-only view model, a deliberate widening) must *say* so
 * by not being the DTO's shape. See ADR-0013.
 */
$sourceRoot = dirname(__DIR__, 2);

/**
 * Every `#[TypeScript]` DTO in `app/Data/`, as name => its field names.
 *
 * The fields are the constructor's promoted properties, which is exactly what the
 * transformer emits.
 *
 * @return array<string, array<int, string>>
 */
$dtoShapes = function () use ($sourceRoot): array {
    $shapes = [];

    foreach ((new Finder)->files()->in($sourceRoot.'/app/Data')->name('*.php') as $file) {
        /** @var class-string $class */
        $class = 'App\\Data\\'.$file->getBasename('.php');

        $reflection = new ReflectionClass($class);

        if ($reflection->getAttributes(TypeScript::class) === []) {
            continue;
        }

        $fields = array_map(
            fn (ReflectionParameter $parameter): string => $parameter->getName(),
            $reflection->getConstructor()?->getParameters() ?? [],
        );

        sort($fields);

        $shapes[$reflection->getShortName()] = $fields;
    }

    return $shapes;
};

/**
 * Every backed enum in `app/Enums/`, as name => its case values.
 *
 * The transformer's enum collector emits all of them, so each is already an
 * `App.Enums.*` union on the client. A pure enum has no case values to restate, so
 * it is skipped rather than read for a `value` it does not have.
 *
 * @return array<string, array<int, string>>
 */
$enumCases = function () use ($sourceRoot): array {
    $cases = [];

    foreach ((new Finder)->files()->in($sourceRoot.'/app/Enums')->name('*.php') as $file) {
        /** @var class-string<BackedEnum> $enum */
        $enum = 'App\\Enums\\'.$file->getBasename('.php');

        if (! enum_exists($enum)) {
            continue;
        }

        if (! is_subclass_of($enum, BackedEnum::class)) {
            continue;
        }

        $values = array_map(
            fn (BackedEnum $case): string => (string) $case->value,
            $enum::cases(),
        );

        sort($values);

        $cases[(new ReflectionClass($enum))->getShortName()] = $values;
    }

    return $cases;
};

/**
 * Every `export type Name = { ... }` in `resources/js/types/`, as name => its
 * top-level field names.
 *
 * Deliberately a line scan rather than a TypeScript parse: the directory is
 * Prettier-formatted at four spaces, so a top-level member is the only thing
 * indented exactly one level inside the literal, and a nested shape's members sit
 * deeper. A field's optionality and type are irrelevant here — this asks only
 * *which facts* a type restates.
 *
 * @return array<string, array<int, string>>
 */
$declaredObjectShapes = function () use ($sourceRoot): array {
    $shapes = [];

    foreach ((new Finder)->files()->in($sourceRoot.'/resources/js/types')->name('*.ts') as $file) {
        $lines = explode("\n", $file->getContents());

        $current = null;
        $fields = [];

        foreach ($lines as $line) {
            if ($current === null) {
                if (preg_match('/^export type (\w+) = \{$/', $line, $matches) === 1) {
                    $current = $matches[1];
                    $fields = [];
                }

                continue;
            }

            if ($line === '};') {
                sort($fields);
                $shapes[$current] = $fields;
                $current = null;

                continue;
            }

            if (preg_match('/^ {4}(\w+)\??:/', $line, $matches) === 1) {
                $fields[] = $matches[1];
            }
        }
    }

    return $shapes;
};

/**
 * Every `export type Name = 'a' | 'b'` in `resources/js/types/`, as name => its
 * string literals. Both the single-line and the wrapped form Prettier produces.
 *
 * @return array<string, array<int, string>>
 */
$declaredUnions = function () use ($sourceRoot): array {
    $unions = [];

    foreach ((new Finder)->files()->in($sourceRoot.'/resources/js/types')->name('*.ts') as $file) {
        $source = preg_replace('/\s*\n\s*\|/', ' |', $file->getContents()) ?? '';

        preg_match_all("/^export type (\w+) =((?: \|)? '[^']*'(?: \| '[^']*')*);$/m", $source, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            preg_match_all("/'([^']*)'/", $match[2], $literals);

            $values = $literals[1];
            sort($values);

            $unions[$match[1]] = $values;
        }
    }

    return $unions;
};

test('no hand-written type restates a generated DTO', function () use ($dtoShapes, $declaredObjectShapes): void {
    $dtos = $dtoShapes();
    $declared = $declaredObjectShapes();

    // Both halves are scanned rather than declared, so a moved directory or a
    // reformatted `resources/js/types/` would leave this test comparing nothing
    // against nothing and passing for the wrong reason. Two known survivors — a
    // DTO and a client-only view model that must never become an alias — pin that
    // each scanner is still finding what it is looking at.
    expect($dtos)->toHaveKey('MessageData')
        ->and($declared)->toHaveKey('MessagePage');

    $shadows = [];

    foreach ($declared as $name => $fields) {
        if ($fields === []) {
            continue;
        }

        foreach ($dtos as $dto => $dtoFields) {
            if ($fields === $dtoFields) {
                $shadows[] = "{$name} restates App.Data.{$dto}";
            }
        }
    }

    sort($shadows);

    expect($shadows)->toBe([]);
});

test('no hand-written union restates a generated enum', function () use ($enumCases, $declaredUnions): void {
    $enums = $enumCases();
    $declared = $declaredUnions();

    // Same reason as above: a union scanner that has stopped matching would report
    // no shadows and read as green.
    expect($enums)->toHaveKey('MessageType')
        ->and($declared)->toHaveKey('Appearance');

    $shadows = [];

    foreach ($declared as $name => $values) {
        foreach ($enums as $enum => $enumValues) {
            if ($values === $enumValues) {
                $shadows[] = "{$name} restates App.Enums.{$enum}";
            }
        }
    }

    sort($shadows);

    expect($shadows)->toBe([]);
});
