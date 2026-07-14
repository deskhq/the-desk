---
title: Installation
description: Install The Desk with Docker — either pull the prebuilt image or build from source at a release tag.
---

The production stack is orchestrated with `docker-compose.prod.yml`. The app is
served with [FrankenPHP](https://frankenphp.dev/); Postgres, Meilisearch, Redis,
Reverb, a queue worker, and the scheduler all run as containers.

There are **two supported ways** to get the app image, both driven by the same
`.env`:

- **[Pull the prebuilt image](#option-a-pull-the-published-image)** from the
  GitHub Container Registry (`ghcr.io/emmpaul/the-desk`) — no build step. Every
  setting, including the browser-facing Reverb values, is read at **runtime**, so
  one published image works for any host.
- **[Build from source](#option-b-build-from-source)** at a release tag — clone
  the repo, check out a tag, and `--build`.

:::note
Make sure you meet the [requirements](/docs/self-hosting/requirements/) first — Docker
24+, a domain, a TLS reverse proxy, and working SMTP.
:::

## Option A — Pull the published image

Each release publishes a prebuilt image to the GitHub Container Registry at
`ghcr.io/emmpaul/the-desk` (tags `X.Y.Z`, `X.Y`, and `latest`; `edge` tracks the
tip of `master`). Because the app name and the browser-facing Reverb settings are
served to the frontend at **runtime** — not baked into the JavaScript bundle at
build time — the same image works for any operator's host with no rebuild.

```bash
# 1. Grab the compose file, env template, and secret generator from a release tag.
git clone git@github.com:emmpaul/the-desk.git
cd the-desk
git fetch --tags && git checkout v1.2.2 # x-release-please-version   (the desired release tag)

# 2. Generate .env secrets, then edit APP_URL, mail, and REVERB_*_PUBLIC.
./docker/gen-secrets.sh

# 3. Run the published image instead of building. Pin the version to the tag.
echo 'APP_IMAGE=ghcr.io/emmpaul/the-desk:1.2.2' >> .env # x-release-please-version
docker compose -f docker-compose.prod.yml pull
docker compose -f docker-compose.prod.yml up -d
```

`docker compose pull` fetches the prebuilt image; `up -d` runs it without a build
step. Upgrades are just `APP_IMAGE=…:X.Y.Z`, then `pull` + `up -d` again — see
[Upgrading](/docs/self-hosting/upgrading/).

## Option B — Build from source

```bash
# 1. Clone and check out the latest release tag.
git clone git@github.com:emmpaul/the-desk.git
cd the-desk
git fetch --tags
git checkout v1.2.2 # x-release-please-version         (the desired release tag)

# 2. Generate .env with all required secrets filled in.
#    Creates .env from the template and fills APP_KEY, DB_PASSWORD,
#    MEILISEARCH_KEY, and the REVERB_* keys with fresh random values.
#    Safe to re-run — it never overwrites values you have already set.
./docker/gen-secrets.sh

# 3. Edit .env and set the non-secret settings the script can't guess
#    (APP_URL, SMTP mail credentials, REVERB_*_PUBLIC). See Configuration.

# 4. Build the images and start the stack.
docker compose -f docker-compose.prod.yml up -d --build
```

## What happens on start

- **Migrations run automatically.** The `app` container's entrypoint runs
  `php artisan migrate --force` on boot.
- The app is published on **`APP_PORT`** (default `80`) and Reverb on
  **`REVERB_PORT`** (default `8080`). Point your reverse proxy at them.

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
