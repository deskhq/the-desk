# Concurrent worktrees for parallel agents

Several agents can implement different issues at the same time, each in its own
git worktree + Sail instance, without colliding on host ports, containers, or
dependencies. The tooling is [`deskhq/laravel-worktree`][package], installed as a
dev dependency and driven through `vendor/bin/worktree`; the `/implement-issue`
skill self-bootstraps through it as its step 0.

This used to be `bin/worktree`, a thousand lines of bash committed at the repo
root. Everything it did that was *mechanism* now lives in the package. What is
left in this repository is one config file — [`config/worktree.php`][config] —
plus `bin/worktree-playwright`, and both are about the-desk specifically.

[package]: https://github.com/deskhq/laravel-worktree
[config]: ../config/worktree.php

## Why it works

Git worktrees already isolate the working tree, and Sail already isolates
containers, the network, and named volumes per Compose project (keyed off
`COMPOSE_PROJECT_NAME`). The only things that genuinely collide across
concurrent worktrees are **host port bindings** and each worktree's own on-host
`vendor/` + `node_modules/`. The package hands each worktree a unique port block
and its own Compose project, and runs the bootstrap this repository configures.

## What the-desk configures, and why

The test gate only touches Postgres — `phpunit.xml` sets cache/session to
`array`, queue to `sync`, Scout to `collection`, and broadcast to `null`. The
bootstrap's own `artisan` calls do not run under `phpunit.xml` though: they read
the worktree's `.env`, where `CACHE_STORE` and `QUEUE_CONNECTION` are `redis`, so
the demo seed reaches the cache (`WorkspaceSeeder` → `CreateChannel` →
`JoinChannel`) and needs a live `redis` to talk to. So `compose.keep_services` is
`['pgsql', 'redis']`, which trims `laravel.test`'s `depends_on` to those two and
makes `sail up -d laravel.test` start exactly three containers instead of seven.

The **Browser suite** (`tests/Browser`) is the exception: it drives a real
Chromium and `tests/Pest.php` points the broadcaster at a live Reverb for the
whole suite. So the bootstrap also starts `reverb` and installs the Playwright
browsers — four containers in total. Three details make that work:

- **Reverb is started by a step, not by `depends_on`.** `reverb:start` exits on a
  missing `APP_KEY` and nothing would restart it, so it has to come up after the
  key-generation step rather than alongside the app service.
- **`REVERB_PORT` is overloaded** in `compose.yaml`: it is both the host side of
  `${REVERB_PORT}:8080` and — via `config/broadcasting.php` — the port the app
  dials at `reverb:<port>`. The generated `.env` pins it to the container-internal
  `8080`, and the per-worktree host offset moves into `compose.port_overrides`,
  so concurrent worktrees still don't fight over it.
- **Playwright is the one step allowed to fail.** Its browser binaries ship out of
  band and the install reaches third-party networks, so it lives in
  `bin/worktree-playwright` with `allow_failure` and a `degrade` message. The
  worktree still reaches ready; only `tests/Browser` is unavailable, and
  re-entering retries that step and only that step. See the script's own header
  for the apt-source parking that made #1005 survivable.

`FORWARD_REDIS_PORT` is offset even though nothing dials redis from the host:
`reverb` pulls it in through its own `depends_on`, and redis publishes
`${FORWARD_REDIS_PORT:-6379}:6379` when it does. Without the offset the second
worktree to start reverb dies on `Bind for :::6379 failed`.

Everything else in `config/worktree.php` is commented in place.

## Commands

```bash
vendor/bin/worktree create <NNN> [base]   # create (or re-enter) an isolated
                                          # worktree; prints its path on stdout
vendor/bin/worktree list                  # active worktrees, slots and ports
vendor/bin/worktree remove <NNN>          # tear down containers + volumes,
                                          # remove the worktree, free the slot
vendor/bin/worktree reap                  # reclaim orphaned Docker resources
```

Typical use, straight from the main checkout:

```bash
cd "$(vendor/bin/worktree create 441)"   # drops you into the isolated worktree
./vendor/bin/sail composer test          # the full gate against this worktree
```

`create` allocates the lowest free **slot** (10 of them, `WORKTREE_SLOTS`) under
a lock and derives the whole port block from it (`WORKTREE_PORT_BASE`, default
`20000`; slot 0 → app 20000 / vite 20001 / reverb 20002 / db 20003 / redis
20004). Worktrees live in `../the-desk-worktrees/<NNN>-<slug>/`.

**The base branch defaults to `develop`**, pinned in `config/worktree.php`. The
bash original defaulted to `master` and every caller had to remember to pass
`develop` explicitly; `master` is currently 27 commits behind, so forgetting
meant a worktree forked from a stale baseline — the shape of #639. Pass a base
explicitly only when you want something else: a foundation branch for a
stacked-epic child, or `master` for a hotfix.

`php artisan worktree:*` exists but refuses from inside the container, which is
where artisan runs under Sail. Use the binary.

## Conventions

- **One Claude Code session per agent.** Each agent runs in its own session and
  its own worktree; don't drive two issues from one session (they would fight
  over `cd`). Use `vendor/bin/worktree list` to see who is where.
- **Manual teardown.** Nothing is auto-destroyed — a finished worktree stays
  browsable for follow-up review. Run `vendor/bin/worktree remove <NNN>` once the
  PR is merged. The branch is left intact.
- **Re-entry is idempotent.** Re-running `create` on a ready worktree just
  re-prints its path; an interrupted bootstrap resumes. The demo seed is guarded
  by a `.worktree-seeded` sentinel, and until it exists the schema is rebuilt
  (`migrate:fresh --seed`) rather than migrated in place — so a seed that died
  partway through cannot leave rows that fail the unique constraints on retry.

## Notes & limits

- **stdout carries machine-readable output only** — the path from `create`, the
  table from `list` — so `cd "$(vendor/bin/worktree create <NNN>)"` is composable
  even on a fresh bootstrap, where Composer, npm and artisan each write
  kilobytes. Progress is still shown; it is all on stderr.
- Dependencies are installed per worktree (isolation over speed). The first
  worktree pays the Sail image build; later ones reuse the cached image.
- Running the **full** coverage gate in several worktrees at once is bound by
  the memory you give Docker/OrbStack — PHPStan is memory-hungry, so give the
  VM enough headroom (≈2-3 GB per concurrent gate) if you run many at once.
- `vendor/` bootstraps through `laravelsail/php84-composer` (the highest composer
  image Sail publishes), then the authoritative `composer install` runs inside
  the php8.5 app container. Override with `WORKTREE_COMPOSER_IMAGE`.

## Migrating from `bin/worktree`

Three things changed name, and the third one bites:

| Was | Now | Consequence |
| --- | --- | --- |
| `compose.override.yaml` | `compose.worktree.yaml` + `SAIL_FILES` | stops clobbering an override file of your own |
| `~/.the-desk/worktrees.json` | `~/.laravel-worktree/registry.json` | one-time move; the old file is not read |
| project `desk-<NNN>` | `wt-the-desk-<NNN>-<slug>` | **existing worktrees' Docker resources become unreachable by the new tool** |

That last row is the one to act on. `reap` scopes itself by project name, and the
new tool only ever looks at `wt-`-prefixed projects — so any `desk-<NNN>` volume
still on the Docker disk is invisible to it. **Run `bin/worktree reap` with the
old script before deleting it**, or reclaim the leftovers by hand:

```bash
docker volume ls --filter 'label=com.docker.compose.project=desk-<NNN>'
docker compose -p desk-<NNN> down --volumes --remove-orphans
```
