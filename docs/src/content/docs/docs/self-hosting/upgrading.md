---
title: Upgrading
description: Tag-based upgrades with automatic migrations and version-scoped search reindexing.
---

Upgrades follow the same tag-based flow you used to install, whether you run the
published image (the default) or build from source.

## These docs track the in-development version

This site is published from `master` on every change, so it always describes the
latest development version of The Desk. The Docker image you run, however, only
changes when a release is tagged: the versioned and `latest` images are published
by the release automation on each `v*` tag, while `master` only moves the rolling
`edge` tag.

That means a feature can be documented here before it appears in any released
image. The site-wide banner names the **latest released version**; anything
documented but not yet in that release is coming in a future one. Check the
[CHANGELOG](https://github.com/emmpaul/the-desk/blob/master/CHANGELOG.md) to
confirm which release a given feature shipped in, and pin your `APP_IMAGE` (or
checkout tag) to a released version rather than `edge` for a stable deployment.

## Back up first

:::caution
Migrations run automatically on start and can alter your schema irreversibly. A
failed or interrupted upgrade can leave the database in a broken state. **Always
take a backup before upgrading** — especially across a major version.
:::

Two things hold your durable state: the **PostgreSQL database** (all messages,
teams, and users) and the **`storage-app`** volume (uploaded files). The
Meilisearch index is derived data — it is rebuilt from Postgres on boot, so it
needs no backup — and Redis holds only cache, sessions, and transient queued
jobs.

Take a logical database dump (portable across Postgres versions) and an archive
of the uploaded files:

```bash
# Database — logical dump, gzipped
docker compose exec -T pgsql \
  pg_dump -U "${DB_USERNAME:-laravel}" "${DB_DATABASE:-laravel}" \
  | gzip > db-backup-$(date +%F).sql.gz

# Uploaded files — stream a tar out of the running app container
docker compose exec -T app \
  tar czf - -C /app/storage/app . > storage-app-$(date +%F).tar.gz
```

Store both files off the host. To restore into a **freshly created, empty**
database and a running stack:

```bash
# Database
gunzip -c db-backup-YYYY-MM-DD.sql.gz | docker compose exec -T pgsql \
  psql -U "${DB_USERNAME:-laravel}" "${DB_DATABASE:-laravel}"

# Uploaded files
docker compose exec -T app \
  tar xzf - -C /app/storage/app < storage-app-YYYY-MM-DD.tar.gz
```

## Default: pull the newer image

Check out the newer release tag and restart — `up -d` pulls the image that tag
pins:

```bash
git fetch --tags
git checkout v1.5.2 # x-release-please-version         (the desired release tag)
docker compose down
docker compose up -d
# pulls the newer pinned image; migrations run automatically via the entrypoint
```

If you override `APP_IMAGE` in `.env`, point it at the new tag first (e.g.
`APP_IMAGE=ghcr.io/emmpaul/the-desk:<tag>`) before restarting.

:::note
These commands need no `-f docker-compose.prod.yml` because `.env` sets
`COMPOSE_FILE`. See
[the COMPOSE_FILE variable](/docs/self-hosting/installation/#the-compose_file-variable).
If your `.env` predates that variable, keep passing the flag as before, or add
`COMPOSE_FILE=docker-compose.prod.yml` to your `.env` to drop it.
:::

## Build from source

If you build the image locally with the build overlay, check out the newer tag and
rebuild:

```bash
git fetch --tags
git checkout v1.5.2 # x-release-please-version         (the desired release tag)
docker compose down
docker compose up -d --build
# migrations run automatically via the entrypoint
```

This assumes your `.env` lists both files, as the
[build-from-source install](/docs/self-hosting/installation/#build-from-source)
sets up:

```
COMPOSE_FILE=docker-compose.prod.yml:docker-compose.build.yml
```

Migrations run automatically on start — the `app` container's entrypoint runs
`php artisan migrate --force`.

## Confirm the running version

A healthy stack proves the containers are alive, not that they are running the
version you just checked out: the old container answers just as happily as the
new one. Ask the instance directly:

```bash
docker compose exec -T app php artisan app:version
```

It prints the bare version and nothing else, newline-terminated, so you can
compare it against the tag you checked out or capture it in a script:

```bash
running=$(docker compose exec -T app php artisan app:version)
echo "The Desk ${running} is running."
```

If it still reports the version you upgraded from, the old container is most
likely still up. Re-run `docker compose up -d` and check `docker compose ps`.

## Your data persists

Data survives `down`/`up` in named volumes:

- `pgsql-data` — the PostgreSQL database
- `the-desk-meili-<version>` — the Meilisearch index (version-scoped, see below)
- `redis-data` — cache, session, and queued jobs
- `storage-app` — uploaded files

## Search reindexing

The Meilisearch on-disk format is version-specific — it refuses to boot against a
data directory written by a different version. `MEILISEARCH_VERSION` pins **both**
the image tag and the data volume name, so bumping it starts the new Meilisearch
on a **fresh** volume, and the app rebuilds the index from Postgres on boot
(`php artisan search:sync`). No manual dump or migration is needed — the search
index is derived data.

The old volume is left behind; prune it once the new version is healthy:

```bash
docker volume rm the-desk-meili-<old-version>
```

## Major-version upgrades

:::caution
**Major version upgrades may contain breaking changes.** Before upgrading across
a major version, read the
[CHANGELOG](https://github.com/emmpaul/the-desk/blob/master/CHANGELOG.md) and the
corresponding [GitHub Release notes](https://github.com/emmpaul/the-desk/releases)
for any required manual steps.
:::
