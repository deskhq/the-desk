---
title: Upgrading
description: Tag-based upgrades with automatic migrations and version-scoped search reindexing.
---

Upgrades follow the same tag-based flow you used to install, whether you build
from source or run the published image.

## Build-from-source

Check out the newer release tag and rebuild:

```bash
git fetch --tags
git checkout v1.4.0                                   # the desired release tag
docker compose -f docker-compose.prod.yml down
docker compose -f docker-compose.prod.yml up -d --build
# migrations run automatically via the entrypoint
```

## Published image

Point `APP_IMAGE` at the new tag, then pull and restart:

```bash
sed -i 's|^APP_IMAGE=.*|APP_IMAGE=ghcr.io/emmpaul/the-desk:1.4.0|' .env
docker compose -f docker-compose.prod.yml pull
docker compose -f docker-compose.prod.yml up -d
```

Migrations run automatically on start — the `app` container's entrypoint runs
`php artisan migrate --force`.

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
