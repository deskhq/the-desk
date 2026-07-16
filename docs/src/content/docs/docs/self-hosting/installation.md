---
title: Installation
description: Install The Desk with Docker — either pull the prebuilt image or build from source at a release tag.
---

The production stack is orchestrated with `docker-compose.prod.yml`. The app is
served with [FrankenPHP](https://frankenphp.dev/); Postgres, Meilisearch, Redis,
Reverb, a queue worker, and the scheduler all run as containers.

The stack **pulls a prebuilt image** by default, and can optionally **build from
source** — both driven by the same `.env`:

- **[Pull the published image](#pull-the-published-image)** from the GitHub
  Container Registry (`ghcr.io/emmpaul/the-desk`) — the default, no build step.
  Every setting, including the browser-facing Reverb values, is read at
  **runtime**, so one published image works for any host.
- **[Build from source](#build-from-source)** at a release tag — layer a build
  overlay to compile the image locally.

:::note
Make sure you meet the [requirements](/docs/self-hosting/requirements/) first — Docker
24+, a domain, a TLS reverse proxy, and working SMTP.
:::

## Pull the published image

`docker-compose.prod.yml` pins the app image to this release's published tag on
the GitHub Container Registry (`ghcr.io/emmpaul/the-desk`; tags `X.Y.Z`, `X.Y`,
and `latest`, with `edge` tracking the tip of `master`), so `up -d` pulls and runs
it with **no build step**. Because the app name and browser-facing Reverb settings
are served to the frontend at **runtime** — not baked into the JavaScript bundle —
the same image works for any operator's host.

```bash
# 1. Grab the compose file, env template, and secret generator from a release tag.
git clone git@github.com:emmpaul/the-desk.git
cd the-desk
git fetch --tags && git checkout v1.5.1 # x-release-please-version   (the desired release tag)

# 2. Generate .env secrets, then edit APP_URL, mail, and REVERB_*_PUBLIC.
./docker/gen-secrets.sh

# 3. Start the stack. up -d pulls the release-pinned image (no build step).
docker compose -f docker-compose.prod.yml up -d
```

The checked-out tag pins the image, so no `APP_IMAGE` is needed. To run a
different tag (e.g. `edge`), set `APP_IMAGE=ghcr.io/emmpaul/the-desk:<tag>` in
`.env`. Upgrades just check out a newer tag and restart — see
[Upgrading](/docs/self-hosting/upgrading/).

## Build from source

To build the image yourself — to audit or patch the source, or run in an
air-gapped environment — layer the build overlay (`docker-compose.build.yml`) on
top, which restores a local build for the app services (they share one image):

```bash
# 1. Clone and check out the latest release tag.
git clone git@github.com:emmpaul/the-desk.git
cd the-desk
git fetch --tags
git checkout v1.5.1 # x-release-please-version         (the desired release tag)

# 2. Generate .env with all required secrets, then edit APP_URL, mail, and
#    REVERB_*_PUBLIC (see Configuration) — identical to the pull flow above.
./docker/gen-secrets.sh

# 3. Build the image and start the stack.
docker compose -f docker-compose.prod.yml -f docker-compose.build.yml up -d --build
```

## What happens on start

- **Migrations run automatically.** The `app` container's entrypoint runs
  `php artisan migrate --force` on boot.
- The app and Reverb speak plain HTTP, so they publish to **loopback** by default
  (**`APP_BIND`**, default `127.0.0.1`) on **`APP_PORT`** (default `8000`) and
  **`REVERB_PORT`** (default `8080`). Point a host-based reverse proxy at
  `127.0.0.1:8000` / `127.0.0.1:8080`; a proxy running inside the compose network
  reaches `app:8080` / `reverb:8080` directly and needs no host publishing.

## Required secrets

`APP_KEY`, `DB_PASSWORD`, and `MEILISEARCH_KEY` have **no defaults** — the stack
refuses to start without them. `./docker/gen-secrets.sh` generates all of these
for you; prefer it over setting them by hand.

If you would rather generate `APP_KEY` yourself, any `base64:`-encoded 32-byte
value works:

```bash
docker run --rm dunglas/frankenphp:1-php8.5-alpine \
  php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
```

Next, tune your instance in [Configuration](/docs/self-hosting/configuration/), then
[create the first user and workspace](/docs/self-hosting/first-user/).
