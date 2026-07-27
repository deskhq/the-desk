<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Duplicate keys in a locale catalog (#856)
|--------------------------------------------------------------------------
|
| A JSON object may not hold the same key twice, but nothing complains when it
| does: every parser keeps the last occurrence and drops the earlier
| translation. The file still parses, the catalog still loads, and both call
| sites still resolve — so a mistranslation ships with nothing to report it.
| That is how `"to"` lost its `au` translation to the `à` defined further down.
|
| `json_decode` cannot see the collision either (it collapses duplicates the
| same way), so the guard walks the raw text for key tokens instead.
|
*/

/**
 * Every key token of a JSON document: a quoted string followed by a colon.
 *
 * The scan is a left-to-right walk rather than a regular expression because it
 * must never resync inside a string — a value holding a colon or an escaped
 * quote would otherwise be read as a key.
 *
 * @return list<string>
 */
function catalogKeyTokens(string $json): array
{
    $keys = [];
    $length = strlen($json);
    $index = 0;

    while ($index < $length) {
        if ($json[$index] !== '"') {
            $index++;

            continue;
        }

        $opening = $index++;

        while ($index < $length && $json[$index] !== '"') {
            $index += $json[$index] === '\\' ? 2 : 1;
        }

        $index++;
        $token = substr($json, $opening, $index - $opening);

        $colon = $index;

        while ($colon < $length && ctype_space($json[$colon])) {
            $colon++;
        }

        if (($json[$colon] ?? '') === ':') {
            $keys[] = (string) json_decode($token);
            $index = $colon + 1;
        }
    }

    return $keys;
}

/**
 * The keys a catalog defines more than once, mapped to their occurrence count.
 *
 * @return array<array-key, int>
 */
function duplicateCatalogKeys(string $json): array
{
    return array_filter(
        array_count_values(catalogKeyTokens($json)),
        static fn (int $count): bool => $count > 1,
    );
}

/**
 * Every locale catalog shipped with the application.
 *
 * @return list<string>
 */
function localeCatalogPaths(): array
{
    return array_values(glob(dirname(__DIR__, 2).'/lang/*.json') ?: []);
}

test('the locale catalogs are discoverable, so the guard below cannot pass vacuously', function (): void {
    expect(localeCatalogPaths())->not->toBeEmpty();
});

test('no locale catalog defines the same key twice', function (): void {
    foreach (localeCatalogPaths() as $path) {
        $duplicates = duplicateCatalogKeys((string) file_get_contents($path));

        expect($duplicates)->toBe(
            [],
            basename($path).' defines '.implode(', ', array_keys($duplicates)).' more than once, so only the last translation survives',
        );
    }
});

test('every locale catalog translates each message to a single line', function (): void {
    foreach (localeCatalogPaths() as $path) {
        $messages = (array) json_decode((string) file_get_contents($path), true);

        expect($messages)->not->toBeEmpty(basename($path).' must decode to a message map');

        foreach ($messages as $key => $line) {
            expect($line)->toBeString(basename($path).' must translate '.$key.' to a single line');
        }
    }
});

test('the scan reports a key defined twice', function (): void {
    $catalog = <<<'JSON'
        {
          "requested by :name": "demandé par :name",
          "to": "au",
          "From": "De",
          "to": "à"
        }
        JSON;

    expect(duplicateCatalogKeys($catalog))->toBe(['to' => 2]);
});

test('the scan is not fooled by values that read like keys', function (): void {
    $catalog = <<<'JSON'
        {
          ":action to join the “:team” team.": ":action pour rejoindre l’équipe « :team ».",
          "Quiet hours": "Heures calmes : 22:00",
          "Say \"hello\"": "Dites \"bonjour\" : maintenant",
          "to": "à"
        }
        JSON;

    expect(catalogKeyTokens($catalog))->toBe([
        ':action to join the “:team” team.',
        'Quiet hours',
        'Say "hello"',
        'to',
    ]);
    expect(duplicateCatalogKeys($catalog))->toBe([]);
});

/*
|--------------------------------------------------------------------------
| Keys used but never translated (#978)
|--------------------------------------------------------------------------
|
| Our message keys *are* the English source strings, so a missing translation
| falls back to readable English rather than to a key — which is the point, and
| also why nothing has ever reported one. That is fine for a key nobody has
| translated yet; it is not fine for a key that *was* translated and then
| renamed, because the rename silently drops the locale back to English while
| the catalog keeps the orphaned old entry.
|
| Dropping a terminal full stop renames a key, so this pass needed the guard.
|
| Only *literal* keys are checked. An interpolated or computed key cannot be
| resolved statically, and demanding one would report failures nobody can fix.
|
*/

/**
 * Every literal translation key a source tree asks for.
 *
 * @param  non-empty-string  $pattern  Matches the call, capturing the quoted key.
 * @return list<string>
 */
function literalTranslationKeys(string $directory, string $pattern, string $extensions): array
{
    $keys = [];

    /** @var Iterator<string, SplFileInfo> $files */
    $files = new RegexIterator(
        new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)),
        '/\.('.$extensions.')$/',
    );

    foreach ($files as $file) {
        $path = $file->getPathname();

        // Tests speak in fixtures, not in copy the app ever renders.
        if (str_contains($path, '.test.')) {
            continue;
        }

        // Generated output is not ours to change, and is absent in CI anyway.
        if (str_contains($path, '/generated/')) {
            continue;
        }

        preg_match_all($pattern, (string) file_get_contents($path), $matches);

        foreach ($matches[1] as $literal) {
            $keys[] = stripcslashes(substr($literal, 1, -1));
        }
    }

    return array_values(array_unique($keys));
}

/** The keys the Vue side asks for: `$t('…')`, `t('…')` and `translate('…')`. */
function frontendTranslationKeys(): array
{
    return literalTranslationKeys(
        dirname(__DIR__, 2).'/resources/js',
        '/(?<![\w$.])(?:\$t|t|translate)\(\s*(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")/',
        'ts|vue',
    );
}

/** The keys the PHP side asks for: `__('…')`. */
function backendTranslationKeys(): array
{
    return literalTranslationKeys(
        dirname(__DIR__, 2).'/app',
        '/(?<![\w>$])__\(\s*(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")/',
        'php',
    );
}

test('the key scan finds the keys the app actually uses, so the guard below cannot pass vacuously', function (): void {
    expect(frontendTranslationKeys())->not->toBeEmpty();
    expect(backendTranslationKeys())->not->toBeEmpty();
});

test('every literal key the app uses exists in every locale catalog', function (): void {
    $used = array_unique([...frontendTranslationKeys(), ...backendTranslationKeys()]);
    $catalogs = localeCatalogPaths();

    expect($catalogs)->not->toBeEmpty();

    foreach ($catalogs as $path) {
        $catalog = (array) json_decode((string) file_get_contents($path), true);
        $missing = array_values(array_filter(
            $used,
            static fn (string $key): bool => ! array_key_exists($key, $catalog),
        ));

        expect($missing)->toBe(
            [],
            basename($path).' is missing '.count($missing).' key(s) the app uses, so they fall back to English: '.implode(' | ', array_slice($missing, 0, 20)),
        );
    }
});
