---
title: Upgrading
description: One-command upgrades that back up, start the new release, and verify it is running, with automatic migrations and version-scoped search reindexing.
---

Upgrading is a version bump: set the release you want in `.env` as `APP_VERSION`
and run `./docker/upgrade.sh`, which backs up, starts the new release, and
verifies the instance is actually running it. There is no git checkout — the
compose file pins the image to `APP_VERSION` — so a production box needs no
repository at all. (Build-from-source is the exception; it still uses the tree.)

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
confirm which release a given feature shipped in, and keep `APP_VERSION` on a
released version rather than overriding `APP_IMAGE` to `edge` for a stable deployment.

## Release candidates

Some releases are preceded by **release candidates** — versions tagged
`X.Y.Z-rc.N`, where `X.Y.Z` is the release being prepared and `N` counts up from
zero as further candidates are cut. They come from the `develop` branch as
changes accumulate, and they exist so the next stable release can be tested
before it ships.

:::caution[Not supported for production]
A candidate is not a release. It has not finished testing, it can change
behaviour between one candidate and the next, and it may be withdrawn. Do not run
one against a workspace you care about, and do not treat a candidate you are
running as an upgrade path — the stable release that follows it is what you
should end up on.
:::

You have to opt in explicitly; nothing pulls a candidate on its own. The moving
`latest` tag and the `X.Y` alias always point at stable releases, and
`./docker/upgrade.sh` only moves to the version you name.

To try one, point `--target` at the candidate version:

```bash
./docker/upgrade.sh --target=X.Y.Z-rc.N /srv/backups
```

Candidates are listed on the [releases
page](https://github.com/emmpaul/the-desk/releases) marked **Pre-release**, and
each one's notes carry the exact `ghcr.io` pull reference. There is also a moving
`rc` image tag that always points at the newest candidate — useful for a
throwaway test instance, and a bad idea anywhere else, since it changes under you
without warning:

```bash
APP_IMAGE=ghcr.io/emmpaul/the-desk:rc
```

If you are testing a candidate, do it on a copy: restore a backup into a separate
stack rather than upgrading your live instance. Going back from a candidate to
the stable release means restoring that backup, because migrations the candidate
ran are not reversed.

## Upgrade

Pass the release you want as `--target`; the script writes it to `APP_VERSION`,
so a routine upgrade is one command with no `.env` edit:

```bash
./docker/upgrade.sh --target=1.12.1 /srv/backups # x-release-please-version
```

Prefer to edit `.env` yourself? Bump `APP_VERSION` there and run
`./docker/upgrade.sh /srv/backups` with no `--target` — it upgrades to whatever
`APP_VERSION` holds.

It does three things, stopping at the first that fails:

1. **Backs up** by running [`docker/backup.sh`](#backups) for you, into the
   directory you name (the current one by default).
2. **Starts the new release.** Just before starting it, your `.env` is
   [checked for settings the new release added](#new-settings-in-a-release);
   migrations then run automatically on boot, via the `app` container's
   entrypoint.
3. **Verifies the upgrade landed.** It waits for `/up` to answer, then asks the
   instance what it is actually running and compares that with `APP_VERSION`.

That third step is the one worth understanding. A healthy stack only proves the
containers are alive: the *old* container answers `/up` just as happily as the
new one. So the script confirms identity separately, and an upgrade that quietly
came back on the previous image is reported as a failure rather than a success.

It picks up your setup on its own. Build-from-source installs are detected from
`COMPOSE_FILE` and get built rather than pulled, so the same command works for
both paths.

Useful flags:

| Flag | Why |
| --- | --- |
| `--target=X.Y.Z` | The release to upgrade to. Written to `APP_VERSION` before pulling, and the version the verify step expects. Omit it to use whatever `APP_VERSION` already holds. |
| `--timeout=SECONDS` | How long to wait for `/up` (default 300). A cold boot runs migrations and rebuilds the search index first, so raise it on a slow host or a large database. |
| `--no-pull` | Use the image already on the host, for air-gapped hosts or when you pulled ahead of the window. |
| `--sync-env` | Append any [new settings](#new-settings-in-a-release) to `.env` without asking, for unattended runs that should adopt the template defaults. |
| `--no-sync-env` | Skip the new-settings check entirely. |

### New settings in a release

A new release often introduces settings — a feature toggle, a new tunable — that
your `.env` predates. Without them the app silently falls back to its built-in
defaults, and nothing tells you the option now exists. So before starting the
new release, `upgrade.sh` compares your `.env` against the **target release's**
`.env.prod.example` (read from the image it just pulled, never from a stale
working-tree copy; build-from-source setups use the tree they are about to
build) and reports any active settings the template has that your `.env` lacks.

On an interactive run it then offers to append them with the template's default
values, carrying the template's comment block for each key so the context
travels along. Only *missing* keys are ever appended — a key you already set is
never touched, even when its default changed. Declining leaves `.env` alone and
keeps the report on screen.

Non-interactive runs (cron, CI — anything without a TTY) never block on a
prompt: the report still prints, and nothing is appended unless you pass
`--sync-env`. The check is a courtesy, not a gate — if it cannot run (for
example, the target image predates the template being shipped inside it), the
upgrade proceeds with a note.

You can run the same comparison yourself at any time, against any template
copy:

```bash
./docker/env-sync.sh .env .env.prod.example          # report only
./docker/env-sync.sh .env .env.prod.example --apply  # append what is missing
```

It exits `0` when nothing is missing, `1` after reporting missing keys, and `2`
on usage errors, so it also scripts cleanly.

### If it fails, it stops and hands you the backup

The script **never rolls back on its own**, and that is deliberate.

Rolling back here is not a git revert. It is a destructive database restore, and
from the outside a slow boot is indistinguishable from a broken one: a first boot
that runs migrations and `search:sync`, a wedged search healthcheck, or a proxy
hiccup all look exactly like a failed upgrade for a while, and then recover. A
script that restored automatically would, in that case, destroy every message
written since the dump it took minutes earlier. It would be a data-loss event
caused by the recovery, not the fault, and it would fire at 3am when nobody is
awake to judge it.

So on failure it exits non-zero, stops where it is, and prints the precise
restore command with the backup paths already filled in:

```
Your backup is safe. When you have decided, restore it with:
  ./docker/restore.sh /srv/backups/db-backup-2026-07-17.sql.gz /srv/backups/storage-app-2026-07-17.tar.gz
```

You still have the fresh backup and one command to run. What you do not have is a
script deciding to destroy data on your behalf while you sleep.

:::note
"Stops" is not "reverts". If it got as far as starting the new release, those
containers are up and their migrations have already run: that is the state you
are inspecting, and the state you are deciding about. The guarantee is that
nothing was undone for you, not that nothing changed.
:::

## Backups

:::caution
Migrations run automatically on start and can alter your schema irreversibly. A
failed or interrupted upgrade can leave the database in a broken state.
`upgrade.sh` takes a backup for you before it starts, so an upgrade through it is
always covered. **Take one yourself before any other risky change**, and keep
scheduled backups regardless: the upgrade-time dump is a safety net for that
upgrade, not a backup policy.
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

:::note
Backups run against a live instance and stop nothing, so the database and the
uploads are captured moments apart. A file uploaded in between can end up in one
and not the other, leaving an attachment with no file behind it (or a file
nothing points at) in that backup. Closing that gap would mean taking the
instance down for every backup, including the nightly one, which is a worse trade
than a rare orphan.
:::

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
run that bails leaves the instance as it found it.

The database restore itself runs in a single transaction, covering the schema
replacement and the dump replay together: if anything fails part way, including
the dump becoming unreadable mid-stream, it rolls back and leaves the database as
it was rather than dropped and half-restored. The uploads are extracted to a
staging directory and only swapped in once extraction has fully succeeded, so a
restore that runs out of disk leaves your existing files alone.

Pass `--force` to skip the confirmation for non-interactive use (cron, CI). On a
populated database `--force` also replaces the existing schema outright, which is
what makes the restore land in the freshly created, empty database a dump
expects.

The stack is left stopped afterwards so you can check things over. Start it again
with:

```bash
docker compose up -d
```

## Doing it by hand

`upgrade.sh` is a wrapper around the steps below. They are worth knowing: this is
what it runs, and what to fall back to if you would rather drive each step
yourself.

### Default: pull the newer image

Bump `APP_VERSION` in `.env`, pull, and restart — `up -d` runs the image
`APP_VERSION` pins:

```bash
# set APP_VERSION=1.12.1 in .env # x-release-please-version
docker compose pull
docker compose up -d
# pulls the newer pinned image; migrations run automatically via the entrypoint
```

If you override `APP_IMAGE` in `.env`, point it at the new tag first (e.g.
`APP_IMAGE=ghcr.io/emmpaul/the-desk:<tag>`) before restarting — it takes
precedence over `APP_VERSION`.

:::note
These commands need no `-f docker-compose.prod.yml` because `.env` sets
`COMPOSE_FILE`. See
[the COMPOSE_FILE variable](/docs/self-hosting/installation/#the-compose_file-variable).
If your `.env` predates that variable, keep passing the flag as before, or add
`COMPOSE_FILE=docker-compose.prod.yml` to your `.env` to drop it.
:::

### Build from source

If you build the image locally with the build overlay, check out the newer tag and
rebuild:

```bash
git fetch --tags
git checkout v1.12.1 # x-release-please-version         (the desired release tag)
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

### Confirm the running version

A healthy stack proves the containers are alive, not that they are running the
version you just pinned: the old container answers just as happily as the
new one. Ask the instance directly:

```bash
docker compose exec -T app php artisan app:version
```

It prints the bare version and nothing else, newline-terminated, so you can
compare it against `APP_VERSION` or capture it in a script:

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
