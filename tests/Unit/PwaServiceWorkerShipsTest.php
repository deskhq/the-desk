<?php

declare(strict_types=1);

/**
 * The service worker is emitted at the web root rather than into
 * `public/build`, because a worker only controls the paths below its own URL —
 * from `/build/` it could not cover the app and the instance would stop being
 * installable. The production image copies the bundle directory explicitly, so
 * the worker needs its own copy or it would silently 404 in production while
 * every local check stayed green (#530).
 */
test('the production image ships the service worker at the web root', function (): void {
    $dockerfile = (string) file_get_contents(dirname(__DIR__, 2).'/Dockerfile');

    expect($dockerfile)->toContain('COPY --from=assets /app/public/service-worker.js ./public/service-worker.js');
});

test('the build context never carries a host-built service worker into the image', function (): void {
    $dockerignore = (string) file_get_contents(dirname(__DIR__, 2).'/.dockerignore');

    expect(explode("\n", $dockerignore))->toContain('public/service-worker.js');
});

test('the worker is a build artifact, kept out of version control', function (): void {
    $gitignore = (string) file_get_contents(dirname(__DIR__, 2).'/.gitignore');

    expect(explode("\n", $gitignore))->toContain('/public/service-worker.js');
});
