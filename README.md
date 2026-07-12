# Laravel Slack Clone

A real-time team chat application — workspaces, channels, threads, reactions,
search, and scheduled messages — built with Laravel 13, Inertia + Vue 3, Laravel
Reverb (WebSockets), and Meilisearch.

## Development

Local development uses [Laravel Sail](https://laravel.com/docs/sail):

```bash
git clone git@github.com:emmpaul/laravel-slack-clone.git
cd laravel-slack-clone
cp .env.example .env
composer install
./vendor/bin/sail up -d
./vendor/bin/sail composer setup
```

Run the quality gate before pushing:

```bash
./vendor/bin/sail composer test        # Pint, PHPStan, Rector (dry-run), and tests at 100% coverage
./vendor/bin/sail npm run lint:check    # ESLint / Prettier / vue-tsc / build
```

[Rector](https://github.com/rectorphp/rector) handles automated structural
refactoring (the semantic counterpart to Pint's formatter). The gate runs it in
dry-run mode; when it reports pending changes, apply them and re-run the gate:

```bash
./vendor/bin/sail composer refactor    # apply Rector's suggested refactors
```

## Self-Hosting with Docker

The production stack is orchestrated with `docker-compose.prod.yml`. The app is
served with [FrankenPHP](https://frankenphp.dev/); Postgres, Meilisearch, Reverb,
a queue worker, and the scheduler all run as containers.

There are two supported ways to get the app image, both driven by the same
`.env`:

- **Pull the prebuilt image** from the GitHub Container Registry
  (`ghcr.io/emmpaul/the-desk`) — no build step. Every setting, including the
  browser-facing Reverb values, is read at **runtime**, so one published image
  works for any host. See [Using the published image](#using-the-published-image).
- **Build from source** at a release tag — clone the repo, check out a tag, and
  `--build`. See [First install](#first-install).

### Prerequisites

- Docker Engine 24+ and the Docker Compose plugin.
- A domain and a TLS-terminating reverse proxy (nginx, Caddy, Traefik, …) in
  front of the stack. **TLS/HTTPS is your responsibility** — the containers speak
  plain HTTP. Your proxy must also forward WebSocket upgrade requests to the
  `reverb` service.

### First install

```bash
# 1. Clone and check out the latest release tag.
git clone git@github.com:emmpaul/laravel-slack-clone.git
cd laravel-slack-clone
git fetch --tags
git checkout v0.1.0                                   # the desired release tag

# 2. Generate .env with all required secrets filled in.
#    Creates .env from the template and fills APP_KEY, DB_PASSWORD,
#    MEILISEARCH_KEY, and the REVERB_* keys with fresh random values.
#    Safe to re-run — it never overwrites values you have already set.
./docker/gen-secrets.sh

# 3. Edit .env and set the non-secret settings the script can't guess:
#    APP_URL, SMTP mail credentials, and — since your TLS proxy terminates
#    wss/443 while the container speaks http/8080 — REVERB_PORT_PUBLIC=443 and
#    REVERB_SCHEME_PUBLIC=https. These are read at runtime (a restart applies
#    changes), so no rebuild is needed when they change.

# 4. Build the images and start the stack.
docker compose -f docker-compose.prod.yml up -d --build
```

Migrations run automatically on start (the `app` container's entrypoint runs
`php artisan migrate --force`). The app is published on `APP_PORT` (default `80`)
and Reverb on `REVERB_PORT` (default `8080`); point your reverse proxy at them.

> **Required secrets.** `APP_KEY`, `DB_PASSWORD`, and `MEILISEARCH_KEY` have no
> defaults — the stack refuses to start without them. `gen-secrets.sh` generates
> all of these for you; prefer it over setting them by hand. If you would rather
> generate `APP_KEY` yourself, any `base64:`-encoded 32-byte value works, e.g.
> `docker run --rm dunglas/frankenphp:1-php8.5-alpine php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"`.

### Create the first user and workspace

Registration is open, so onboarding is self-service:

1. Visit your `APP_URL` and go to **/register** to create the first account.
2. Email verification is enabled — make sure your SMTP settings work so the
   verification email is delivered, then verify.
3. Create your first workspace from **Settings → Teams**, then invite teammates.

> **Locking down registration.** Public sign-ups are open by default. To run a
> private/invite-only instance, set `REGISTRATION_ENABLED=false` in `.env`
> (create your own account first). With it off, `/register` returns 404 and the
> "sign up" links are hidden — existing users and email invitations still work.

### Upgrading

Upgrades follow the same tag-based flow. Check out the newer release tag and
rebuild:

```bash
git fetch --tags
git checkout v1.4.0                                   # the desired release tag
docker compose -f docker-compose.prod.yml down
docker compose -f docker-compose.prod.yml up -d --build
# migrations run automatically via the entrypoint
```

Your data persists across `down`/`up` in named volumes (`pgsql-data`,
`the-desk-meili-<version>`, `storage-app`).

> **Meilisearch upgrades reindex automatically.** The search index lives in a
> version-scoped volume, so bumping `MEILISEARCH_VERSION` starts the new
> Meilisearch on a fresh volume and the app rebuilds the index from Postgres on
> boot (`php artisan search:sync`) — no manual dump/migration. The old volume is
> left behind; prune it with `docker volume rm the-desk-meili-<old-version>`.

> **MAJOR version upgrades may contain breaking changes.** Before upgrading
> across a major version, read the [CHANGELOG](CHANGELOG.md) and the
> corresponding [GitHub Release notes](https://github.com/emmpaul/laravel-slack-clone/releases)
> for required manual steps.

### Using the published image

Each release publishes a prebuilt image to the GitHub Container Registry at
`ghcr.io/emmpaul/the-desk` (tags `X.Y.Z`, `X.Y`, and `latest`; `edge` tracks the
tip of `master`). Because the app name and the browser-facing Reverb settings are
served to the frontend at **runtime** — not baked into the JavaScript bundle at
build time — the same image works for any operator's host with no rebuild. Point
it at your `.env` and go:

```bash
# 1. Grab the compose file, env template, and secret generator from a release tag.
git clone git@github.com:emmpaul/the-desk.git
cd the-desk
git fetch --tags && git checkout v0.4.0                # the desired release tag

# 2. Generate .env secrets, then edit APP_URL, mail, and REVERB_*_PUBLIC (see below).
./docker/gen-secrets.sh

# 3. Run the published image instead of building. Pin the version to the tag.
echo 'APP_IMAGE=ghcr.io/emmpaul/the-desk:0.4.0' >> .env
docker compose -f docker-compose.prod.yml pull
docker compose -f docker-compose.prod.yml up -d
```

`docker compose pull` fetches the prebuilt image; `up -d` runs it without a build
step. Upgrades are just `APP_IMAGE=…:X.Y.Z`, then `pull` + `up -d` again.

> **Reverb settings are runtime, so mind the browser vs. server split.** The
> container speaks plain `http` on `8080` (`REVERB_PORT` / `REVERB_SCHEME`), but
> the browser reaches Reverb through your TLS proxy on `wss`/`443`. Set
> `REVERB_PORT_PUBLIC=443` and `REVERB_SCHEME_PUBLIC=https` in `.env`; the
> browser-facing host defaults to your `APP_URL` host (override with
> `REVERB_HOST_PUBLIC` only for a dedicated WebSocket subdomain).

### What runs, and why no Redis

| Service       | Role                                             |
| ------------- | ------------------------------------------------ |
| `app`         | FrankenPHP web server (HTTP + migrations on boot)|
| `reverb`      | WebSocket server for real-time broadcasting      |
| `queue`       | Queue worker (`queue:work`)                      |
| `scheduler`   | Scheduled tasks (`schedule:work`)                |
| `pgsql`       | PostgreSQL database (named volume)               |
| `meilisearch` | Full-text search index (named volume)            |

Cache, session, and queue all use the **database** driver and broadcasting uses
**Reverb**, so nothing resolves to Redis — there is no Redis service in the
production stack.
