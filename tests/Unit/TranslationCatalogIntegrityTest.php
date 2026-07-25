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
    return array_values((array) glob(dirname(__DIR__, 2).'/lang/*.json'));
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
