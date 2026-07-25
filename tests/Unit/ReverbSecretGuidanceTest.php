<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

/**
 * `laravel/reverb` ships `reverb:install`, `reverb:start` and `reverb:restart`
 * and nothing else, so the Dokploy page's `php artisan reverb:secret` failed with
 * `Command "reverb:secret" is not defined.` and left the operator without any of
 * `REVERB_APP_ID`, `REVERB_APP_KEY` or `REVERB_APP_SECRET` — all three of which
 * the stack refuses to start without. `docker/gen-secrets.sh` is the source of
 * truth for how they are minted, so these tests hold the documented recipes to it
 * and check every `reverb:` command we name against the ones actually registered.
 * See #867.
 */
$repoRoot = dirname(__DIR__, 2);

/**
 * Every operator-facing surface that could name a command or a recipe: the
 * published docs plus the environment template an operator copies from.
 *
 * @return list<string>
 */
$operatorGuidance = function () use ($repoRoot): array {
    $files = [$repoRoot.'/.env.prod.example'];

    $docs = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($repoRoot.'/docs/src/content/docs', FilesystemIterator::SKIP_DOTS)
    );

    foreach ($docs as $file) {
        if (in_array($file->getExtension(), ['md', 'mdx'], true)) {
            $files[] = $file->getPathname();
        }
    }

    return $files;
};

/**
 * The `REVERB_APP_*` recipes `docker/gen-secrets.sh` uses, keyed by variable, as
 * the shell command inside each `set_if_empty "KEY" "$(…)"`.
 *
 * @return array<string, string>
 */
$generatedRecipes = function () use ($repoRoot): array {
    preg_match_all(
        '/set_if_empty\s+"(?<key>REVERB_APP_[A-Z_]+)"\s+"\$\((?<recipe>.+)\)"/',
        (string) file_get_contents($repoRoot.'/docker/gen-secrets.sh'),
        $matches,
    );

    return array_combine($matches['key'], $matches['recipe']);
};

test('every documented reverb: artisan command actually exists', function () use ($operatorGuidance): void {
    $registered = array_keys(Artisan::all());
    $invented = [];
    $invocations = 0;

    foreach ($operatorGuidance() as $path) {
        preg_match_all('/\breverb:[a-z][a-z0-9:-]*/', (string) file_get_contents($path), $matches);

        foreach ($matches[0] as $command) {
            $invocations++;

            if (! in_array($command, $registered, true)) {
                $invented[] = $command.' in '.basename((string) $path);
            }
        }
    }

    // Guard the guard: a moved page or a renamed template would empty the scan
    // and leave this passing while checking nothing.
    expect($invocations)->toBeGreaterThan(0)
        ->and($invented)->toBe([]);
});

test('gen-secrets.sh mints all three REVERB_APP_* values, which is what the docs mirror', function () use ($generatedRecipes): void {
    expect(array_keys($generatedRecipes()))
        ->toEqualCanonicalizing(['REVERB_APP_ID', 'REVERB_APP_KEY', 'REVERB_APP_SECRET']);
});

test('the Dokploy page generates each REVERB_APP_* value exactly as gen-secrets.sh does', function () use ($repoRoot, $generatedRecipes): void {
    $page = (string) file_get_contents($repoRoot.'/docs/src/content/docs/self-hosting/dokploy.md');

    foreach ($generatedRecipes() as $key => $recipe) {
        expect($page)->toContain($recipe)
            // The block hands over three interchangeable-looking commands, so
            // each one has to say which value it is for.
            ->and($page)->toContain($key);
    }
});
