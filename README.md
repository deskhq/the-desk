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
./vendor/bin/sail composer test        # Pint, PHPStan, and tests at 100% coverage
./vendor/bin/sail npm run lint:check    # ESLint / Prettier / vue-tsc / build
```

## Self-Hosting with Docker

The supported production stack is built **from source at a release tag** and
orchestrated with `docker-compose.prod.yml`: you clone the repo, check out a
tagged release, and bring the stack up. The app is served with
[FrankenPHP](https://frankenphp.dev/); Postgres, Meilisearch, Reverb, a queue
worker, and the scheduler all run as containers.

A prebuilt image is also published to the GitHub Container Registry, but only as
a **reference/demo image** — see [Pulling the reference image](#pulling-the-reference-image-from-ghcr)
below for its limitations. Build-from-source remains the supported path for real
deployments.

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
#    APP_URL, SMTP mail credentials, and the browser-side VITE_REVERB_HOST /
#    VITE_REVERB_PORT / VITE_REVERB_SCHEME (your public domain + wss/https).
#    VITE_* values are compiled into the frontend at build time, so set them
#    before building.

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
`meili-data`, `storage-app`).

> **MAJOR version upgrades may contain breaking changes.** Before upgrading
> across a major version, read the [CHANGELOG](CHANGELOG.md) and the
> corresponding [GitHub Release notes](https://github.com/emmpaul/laravel-slack-clone/releases)
> for required manual steps.

### Pulling the reference image from GHCR

Each release also publishes a prebuilt image to the GitHub Container Registry at
`ghcr.io/emmpaul/the-desk` (tags `X.Y.Z`, `X.Y`, and `latest`; `edge` tracks the
tip of `master`).

> **This is a reference/demo image, not a turnkey production artifact.** The
> browser-side `VITE_REVERB_APP_KEY`, `VITE_REVERB_HOST`, `VITE_REVERB_PORT`,
> `VITE_REVERB_SCHEME`, and `VITE_APP_NAME` values are compiled into the
> JavaScript bundle **at build time**, so the published image carries one fixed
> set of settings (a `localhost` Reverb host). It is fine for `docker run` and
> clicking around, but real-time broadcasting will point at the wrong host on any
> other domain. For a real deployment, **build from source** (above) so your own
> `VITE_*` values are baked in.

The package is currently **private**, so pulling requires authenticating to GHCR
with a [personal access token](https://github.com/settings/tokens) that has the
`read:packages` scope:

```bash
echo "$GHCR_PAT" | docker login ghcr.io -u YOUR_GITHUB_USERNAME --password-stdin
docker pull ghcr.io/emmpaul/the-desk:latest
```

> **Maintainer note:** the GHCR package is created private on first push. When
> the repository is made public, flip the package to public in its package
> settings (**Package settings → Change visibility**) so unauthenticated pulls
> work — CI cannot toggle this.

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
