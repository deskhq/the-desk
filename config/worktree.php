<?php

/**
 * Per-worktree isolation, as deskhq/laravel-worktree runs it.
 *
 * This replaces bin/worktree, which was a thousand lines of bash. Everything
 * left here is the-desk's own business — which ports it publishes, which
 * services it starts, what its bootstrap has to run. The mechanism lives in the
 * package; see its README for the migration table.
 *
 * Read by `vendor/bin/worktree` on the host with no application booted, so it
 * may use env() and may not reference application classes, container bindings
 * or facades.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Slots and ports
    |--------------------------------------------------------------------------
    |
    | Ten concurrent worktrees, as the bash original allowed. The package's own
    | default is fifty; there is nothing stopping this going up beyond how many
    | Postgres containers this machine wants to run at once.
    |
    | Five ports per slot at stride ten: 20000-20099.
    |
    */

    'slots' => env('WORKTREE_SLOTS', 10),

    'port_base' => env('WORKTREE_PORT_BASE', 20000),

    'port_stride' => 10,

    'ports' => ['app', 'vite', 'reverb', 'db', 'redis'],

    /*
    |--------------------------------------------------------------------------
    | Identity
    |--------------------------------------------------------------------------
    |
    | Pinned to develop, and deliberately not left as null.
    |
    | null would mean "this repository's default branch", which on GitHub is
    | master — and master is currently 27 commits behind develop, because
    | everything here integrates through develop and master is a release line.
    | Every worktree would fork from a stale baseline, and the omission would
    | only surface later as a conflict or a missing commit. That is exactly the
    | shape of the-desk#639.
    |
    | The bash original's DEFAULT_BASE was master for the same reason it was
    | wrong: it was written before develop became the integration branch and
    | never revisited. Naming develop here is a correction, not a translation.
    |
    */

    'base_branch' => env('WORKTREE_BASE', 'develop'),

    'repo_slug' => null,

    /*
    |--------------------------------------------------------------------------
    | Generated .env
    |--------------------------------------------------------------------------
    |
    | Written into a new worktree once, from the main checkout's own .env.
    |
    */

    'env' => [

        // The host side of every port a started service publishes. pgsql and
        // redis publish FORWARD_DB_PORT and FORWARD_REDIS_PORT; laravel.test
        // publishes APP_PORT, and VITE_PORT on both sides at once — which is
        // correct, because laravel-vite-plugin reads it for the dev server's
        // own port, so the two have to move together.
        'APP_PORT' => '{{port.app}}',
        'VITE_PORT' => '{{port.vite}}',
        'FORWARD_DB_PORT' => '{{port.db}}',

        // Not because anything dials redis from the host, but because reverb
        // pulls it in through its own depends_on and it publishes
        // '${FORWARD_REDIS_PORT:-6379}:6379' when it does. Without this the
        // second worktree to start reverb dies on `Bind for :::6379 failed`.
        'FORWARD_REDIS_PORT' => '{{port.redis}}',

        'COMPOSE_PROJECT_NAME' => '{{project}}',
        'APP_URL' => 'http://localhost:{{port.app}}',

        // REVERB_PORT is overloaded in compose.yaml: it is both the host side
        // of '${REVERB_PORT}:8080' and, through config/broadcasting.php, the
        // port the application dials at reverb:<port>. Offsetting it per
        // worktree points the broadcaster at a port nothing listens on, and the
        // realtime browser tests then hang with nothing on screen to explain
        // it. Pin the container-internal value here; the host side is offset in
        // the compose overlay below.
        'REVERB_PORT' => 8080,

        // Auth toggles inherited from the main checkout can strip the register
        // route (config/fortify.php gates Features::registration() on these
        // three), and without it Wayfinder never emits
        // resources/js/routes/register — so `npm run build` dies on an
        // unresolvable import (the-desk#563).
        'DEMO_MODE' => false,
        'REGISTRATION_ENABLED' => true,
        'AUTH_SSO_ONLY' => false,

        // Point everything a worktree never starts somewhere harmless. The
        // main checkout's .env is copied verbatim, so a developer running the
        // opt-in `sso` profile would otherwise hand the worktree LDAP_HOST=ldap
        // with no ldap container behind it — and Fortify then fails every
        // password login with "Can't contact LDAP server", which reads as an
        // application bug rather than a bootstrap one (the-desk#680).
        'COMPOSE_PROFILES' => '',

        // Blank hosts switch SSO off; config/sso.php gates on filled().
        'LDAP_HOST' => '',
        'SSO_OIDC_ISSUER' => '',
        'SSO_OIDC_CLIENT_ID' => '',
        'SSO_OIDC_DISCOVERY_URL' => '',

        // Mail and search fall back to drivers that need no container.
        'MAIL_MAILER' => 'log',
        'MAIL_HOST' => '',
        'SCOUT_DRIVER' => 'collection',
        'MEILISEARCH_HOST' => '',
    ],

    /*
    |--------------------------------------------------------------------------
    | Compose overlay
    |--------------------------------------------------------------------------
    |
    | Written as compose.worktree.yaml and handed to Compose through SAIL_FILES.
    | The bash original wrote compose.override.yaml, which Compose auto-loads —
    | fine for one application that has no override file of its own, wrong for
    | anything that does.
    |
    */

    'compose' => [

        // laravel.test declares depends_on: pgsql, redis, meilisearch, mailpit,
        // reverb, queue. The test gate only touches Postgres (phpunit.xml runs
        // cache and session on array, queue sync, scout collection, broadcast
        // null), and the bootstrap's own artisan calls only add redis — the
        // worktree .env runs cache and queue on it, and the demo seed reaches
        // the cache through CreateChannel/JoinChannel (the-desk#723).
        //
        // So `sail up -d laravel.test` starts three containers rather than
        // seven, and six sibling worktrees do not run six copies of
        // Meilisearch. reverb is started by the bootstrap instead, below.
        'keep_services' => ['pgsql', 'redis'],

        // The host side of the port .env had to pin — see REVERB_PORT above.
        'port_overrides' => ['reverb' => ['{{port.reverb}}:8080']],
    ],

    /*
    |--------------------------------------------------------------------------
    | Bootstrap steps
    |--------------------------------------------------------------------------
    |
    | The package has already brought vendor/ far enough to obtain Sail and run
    | `sail up -d laravel.test` by the time the first of these runs.
    |
    */

    'steps' => [

        // The throwaway composer image that produced vendor/ could not run
        // Laravel's post-install scripts and lacks the application's extensions
        // (gd, imagick, ldap, sockets). This is the authoritative install.
        [
            'label' => 'Finalizing PHP dependencies',
            'sail' => 'composer install --no-interaction',
        ],

        // A freshly copied .env carries APP_KEY=, so the key is present and
        // blank; a condition that only fired on an absent variable would never
        // fire at all.
        [
            'label' => 'Generating the application key',
            'sail' => 'artisan key:generate --force',
            'when' => 'env_empty:APP_KEY',
        ],

        // Seeding is a branch rather than a skip. Each worktree gets its own
        // Postgres volume, so the bootstrap starts on an empty database and the
        // login form has no account to accept — signing in to the isolated
        // instance is the point (the-desk#680).
        //
        // Until the sentinel exists the schema is rebuilt rather than migrated
        // in place: a seed that died partway through leaves rows behind, and
        // the next create then dies on `duplicate key value violates unique
        // constraint "users_email_unique"` instead of resuming (the-desk#723).
        // Dropping the tables first is safe precisely because the sentinel is
        // missing — no seed has ever completed against them.
        [
            'label' => 'Migrating a fresh database and seeding the demo workspace',
            'sail' => 'artisan migrate:fresh --seed --force',
            'sentinel' => '.worktree-seeded',
        ],

        // Once it is there the data is real, and only new migrations run — so a
        // rebased worktree still picks them up.
        [
            'label' => 'Running database migrations',
            'sail' => 'artisan migrate --force',
            'when' => 'exists:.worktree-seeded',
        ],

        // In the container rather than on the host: the native bindings are
        // Linux-only.
        [
            'label' => 'Installing JS dependencies',
            'sail' => 'npm install --no-progress',
        ],

        // The one step allowed to fail. It reaches third-party networks, and
        // abandoning several minutes of Composer and npm work because a mirror
        // returned a 403 is what made the-desk#1005 painful. The worktree still
        // reaches ready; only tests/Browser is unavailable, and re-entering
        // retries this step and only this step.
        [
            'label' => 'Installing the Playwright browsers',
            'host' => 'bin/worktree-playwright {{path}}',
            'allow_failure' => true,
            'degrade' => 'Playwright is not fully installed; tests/Browser will not run in this worktree',
        ],

        // The Browser suite points the broadcaster at a live reverb
        // (tests/Pest.php calls useReverbForBrowserTests for the whole suite).
        // Started here rather than through laravel.test's depends_on so it
        // boots after APP_KEY exists — reverb:start exits on a missing key, and
        // nothing would restart it.
        [
            'label' => 'Starting reverb for the Browser suite',
            'host' => './vendor/bin/sail up -d reverb',
        ],

        // The feature suite renders Inertia pages, which 500 without a Vite
        // manifest, so a testable worktree has to build assets. The git-ignored
        // App.Data/App.Enums ambient types are regenerated first — the
        // transformer needs its output directory to exist — so the build can
        // resolve the `App` namespace. Mirrors the CI ordering in
        // .github/workflows/tests.yml.
        [
            'label' => 'Preparing the generated TypeScript directory',
            'host' => 'mkdir -p resources/js/generated',
        ],

        [
            'label' => 'Generating TypeScript types',
            'sail' => 'artisan typescript:transform',
        ],

        [
            'label' => 'Building frontend assets',
            'sail' => 'npm run build',
        ],
    ],

];
