<?php

declare(strict_types=1);

/**
 * The production stack bind-mounts `.env` read-only, so the file-writing form of
 * `webpush:vapid` cannot succeed inside the app container: an operator following
 * it gets `file_put_contents(/app/.env): Failed to open stream: Read-only file
 * system`. The `--show` form prints the pair instead, and it is the only shape
 * that also works on platform-managed deployments (Dokploy, Coolify, Kubernetes)
 * where no host `.env` exists at all. These tests keep the working command
 * documented and its rationale attached to the fact that forces it. See #863.
 */
$repoRoot = dirname(__DIR__, 2);

/**
 * Every operator-facing surface that could name the command: the published docs
 * and the config file's own guidance block, which had drifted the same way.
 *
 * @return list<string>
 */
$operatorGuidance = function () use ($repoRoot): array {
    $files = [$repoRoot.'/config/webpush.php'];

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

test('every documented webpush:vapid invocation prints the keys rather than writing them', function () use ($operatorGuidance): void {
    $writesToEnv = [];
    $invocations = 0;

    foreach ($operatorGuidance() as $path) {
        // Capture what follows the command up to the end of the line or the
        // closing backtick, so an invocation is judged by its own flags rather
        // than by a `--show` mentioned elsewhere in the file.
        preg_match_all('/webpush:vapid(?<flags>[^\n`]*)/', (string) file_get_contents($path), $matches);

        foreach ($matches['flags'] as $flags) {
            $invocations++;

            if (! str_contains($flags, '--show')) {
                $writesToEnv[] = basename((string) $path);
            }
        }
    }

    // Guard the guard: a rename or a moved page would empty the scan and leave
    // this passing while checking nothing.
    expect($invocations)->toBeGreaterThan(0)
        ->and($writesToEnv)->toBe([]);
});

test('the production stack still mounts .env read-only, which is what forces --show', function () use ($repoRoot): void {
    expect((string) file_get_contents($repoRoot.'/docker-compose.prod.yml'))
        ->toContain('./.env:/app/.env:ro');
});

test('the web push setup explains the read-only mount and the keys to paste in', function () use ($repoRoot): void {
    $page = (string) file_get_contents($repoRoot.'/docs/src/content/docs/reference/environment-variables.md');

    expect($page)->toContain('./.env:/app/.env:ro')
        ->and($page)->toContain('Read-only file system')
        // VAPID_SUBJECT is set by hand in the same paste, since --show never prints it.
        ->and($page)->toContain('VAPID_SUBJECT=');
});
