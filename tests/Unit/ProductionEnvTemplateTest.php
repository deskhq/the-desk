<?php

declare(strict_types=1);

/**
 * A PaaS environment editor (Dokploy, Coolify, …) writes its textarea to `.env`
 * verbatim but normalises quotes, so `APP_NAME="The Desk"` lands as
 * `APP_NAME=The Desk` and phpdotenv aborts the boot with `Failed to parse dotenv
 * file. Encountered unexpected whitespace at [The Desk]` — an error that names
 * neither the file nor the key, hidden behind a container restart loop.
 *
 * The same values also reach the container through compose's `env_file:`, which
 * passes them through literally, so a `${VAR}` reference is never expanded: it
 * arrives as the characters an operator typed. See issue #751.
 *
 * Commented-out settings are checked too — they are the lines an operator
 * uncomments, so a hazard parked behind a `#` is a hazard shipped.
 *
 * @return list<array{key: string, value: string, line: int, commented: bool}>
 */
$settings = function (): array {
    $template = (string) file_get_contents(dirname(__DIR__, 2).'/.env.prod.example');
    $settings = [];

    foreach (explode("\n", $template) as $index => $line) {
        if (preg_match('/^(#?)\s*([A-Z][A-Z0-9_]*)=(.*)$/', trim($line), $matches) !== 1) {
            continue;
        }

        $settings[] = [
            'commented' => $matches[1] === '#',
            'key' => $matches[2],
            // Drop the trailing inline comment dotenv itself strips, so an
            // annotation such as `# x-release-please-version` is not read as
            // whitespace inside the value.
            'value' => (string) preg_replace('/\s+#.*$/', '', $matches[3]),
            'line' => $index + 1,
        ];
    }

    return $settings;
};

/**
 * The one setting whose value cannot be expressed without spaces: Socialite is
 * handed `explode(' ', …)` of it. The exemption is deliberately narrowed to the
 * commented-out example it ships as, so activating it in the template would have
 * to be a conscious decision rather than an inherited licence.
 *
 * @var list<string>
 */
$quotedByNecessity = ['SSO_OIDC_SCOPES'];

test('the template is parsed, not silently matched to nothing', function () use ($settings): void {
    expect(count($settings()))->toBeGreaterThan(50);
});

test('no setting breaks when a PaaS strips its quotes', function () use ($settings, $quotedByNecessity): void {
    $fragile = array_values(array_map(
        static fn (array $setting): string => $setting['key'].' (line '.$setting['line'].')',
        array_filter(
            $settings(),
            static fn (array $setting): bool => (! $setting['commented'] || ! in_array($setting['key'], $quotedByNecessity, true))
                && preg_match('/\s/', $setting['value']) === 1,
        ),
    ));

    expect($fragile)->toBe([]);
});

test('no setting references another one, which env_file would not expand', function () use ($settings): void {
    $referencing = array_values(array_map(
        static fn (array $setting): string => $setting['key'].' (line '.$setting['line'].')',
        array_filter($settings(), static fn (array $setting): bool => str_contains($setting['value'], '${')),
    ));

    expect($referencing)->toBe([]);
});
