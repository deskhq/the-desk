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

Take both with one command, from the project root:

```bash
./docker/backup.sh
```

It writes a gzipped logical database dump (SQL statements rather than a physical
copy of the data files, which is what makes it portable across Postgres versions
in the ordinary case, though restoring into a very different major version or a
host missing an extension can still need work) and an archive of the uploaded
files into the current directory, named with the date:

```
db-backup-YYYY-MM-DD.sql.gz
storage-app-YYYY-MM-DD.tar.gz
```

Pass a directory to write them somewhere else, and `--keep=N` to prune all but
the N most recent backup pairs in it:

```bash
./docker/backup.sh /srv/backups --keep=7
```

The script checks there is enough free space before it starts and refuses rather
than filling the host disk, and it never leaves a truncated file behind that
could be mistaken for a good backup. `pg_dump` runs inside the `pgsql` container,
so its version always matches the server.

Store both files off the host. They are an ordinary gzipped `pg_dump` and an
ordinary gzipped tar of `storage/app`, so any backup tooling you already run can
consume them.

### Scheduled backups

Run the script from **host cron**:

```cron
0 3 * * * cd /srv/the-desk && ./docker/backup.sh /srv/backups --keep=7
```

The app's own `scheduler` container cannot do this. It runs inside Docker and
would need the Docker socket mounted to drive `docker compose`, which is
root-equivalent access to the host: too much to trade for a cron line.

### Restoring

```bash
./docker/restore.sh db-backup-YYYY-MM-DD.sql.gz storage-app-YYYY-MM-DD.tar.gz
```

Restore is destructive in a way backup is not, so the script:

- **stops `app`, `reverb`, `queue`, and `scheduler` first**, so nothing writes to
  the database or the uploads mid-restore;
- **refuses a non-empty database** unless `--force` is passed, because `psql`
  replaying a dump over existing tables produces a half-merged database rather
  than the backup you asked for;
- **prints exactly what it will overwrite** and asks you to confirm.

Every check that can refuse runs before anything is stopped or overwritten, so a
run that bails leaves the instance as it found it. The database restore itself
runs in a single transaction: if it fails part way, it rolls back and leaves the
database as it was, rather than dropped and half-restored.

Pass `--force` to skip the confirmation for non-interactive use (cron, CI). On a
populated database `--force` also replaces the existing schema outright, which is
what makes the restore land in the freshly created, empty database a dump
expects.

The stack is left stopped afterwards so you can check things over. Start it again
with:

```bash
docker compose up -d
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
likely still up. Check `docker compose ps`, then re-run the `up -d` step for
your install path above (`--build` included, if you build from source).

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
