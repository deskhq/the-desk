# Concurrent worktrees for parallel agents

Several agents can implement different issues at the same time, each in its own
git worktree + Sail instance, without colliding on host ports, containers, or
dependencies. The tooling is `bin/worktree` (committed at the repo root); the
`/implement-issue` skill self-bootstraps through it as its step 0.

## Why it works

Git worktrees already isolate the working tree, and Sail already isolates
containers, the network, and named volumes per Compose project (keyed off
`COMPOSE_PROJECT_NAME`). The only things that genuinely collide across
concurrent worktrees are **host port bindings** and each worktree's own on-host
`vendor/` + `node_modules/`. `bin/worktree` hands each worktree a unique port
block and its own Compose project, and installs its deps.

The test gate only touches Postgres — `phpunit.xml` sets cache/session to
`array`, queue to `sync`, Scout to `collection`, and broadcast to `null`. So an
isolated worktree runs just **`laravel.test` + `pgsql`** (2 containers). A
generated `compose.override.yaml` trims `laravel.test`'s `depends_on` to
`pgsql`, and `sail up -d laravel.test` then starts exactly those two.

The **Browser suite** (`tests/Browser`) is the exception: it drives a real
Chromium and `tests/Pest.php` points the broadcaster at a live Reverb for the
whole suite. So the bootstrap additionally installs the Playwright browsers into
the app container and starts `reverb` (which pulls in `redis` through its own
`depends_on`) — four containers in total. Two details make that work:

- Playwright's browser binaries ship out of band; `npm install` fetches only the
  driver. They install in two steps because the shared libraries Chromium links
  against need `apt` and therefore root (`sail root-shell -c "npx playwright
  install-deps chromium"`), while the browser itself installs as the `sail` user
  so its cache lands in the `HOME` Pest reads it back from. Re-entry probes the
  cache and skips both when a chromium build is already unpacked.
- `REVERB_PORT` is overloaded in `compose.yaml`: it is both the host side of
  `${REVERB_PORT}:8080` and — via `config/broadcasting.php` — the port the app
  dials at `reverb:<port>`. The generated `.env` therefore pins it to the
  container-internal `8080`, and the per-worktree host offset moves into the
  override's `reverb.ports`, so concurrent worktrees still don't fight over it.

## Commands

```bash
bin/worktree create <NNN> [base]   # create (or re-enter) an isolated worktree
                                    # for issue NNN; prints its path on stdout
bin/worktree list                  # active worktrees, their slots and ports
bin/worktree remove <NNN>          # tear down containers + volumes, remove the
                                    # worktree, free the slot (branch is kept)
```

`create` allocates the lowest free **slot** (default 10, `WORKTREE_SLOTS`) under
a lock, and derives the whole port block from it (`WORKTREE_PORT_BASE`, default
`20000`; slot 0 → app 20000 / vite 20001 / reverb 20002 / db 20003 / redis
20004). It forks
from `master` by default; pass a foundation branch as `base` for a stacked-epic
child. The base is fetched and resolved to its **remote-tracking** ref
(`origin/<base>`) before forking, so a local branch lagging behind the remote
cannot hand the worktree a stale baseline; a base that exists only locally is
still forked from the local branch. The worktrees live in `../the-desk-worktrees/<NNN>-<slug>/` with an
explicit `COMPOSE_PROJECT_NAME=desk-<NNN>`. State lives in
`~/.the-desk/worktrees.json`.

Typical use, straight from the main checkout:

```bash
cd "$(bin/worktree create 441)"    # drops you into the isolated worktree
./vendor/bin/sail composer test    # runs the full gate against this worktree's stack
```

## Conventions

- **One Claude Code session per agent.** Each agent runs in its own session and
  its own worktree; don't drive two issues from one session (they would fight
  over `cd`). Use `bin/worktree list` to see who is where.
- **Manual teardown.** Nothing is auto-destroyed — a finished worktree stays
  browsable for follow-up review. Run `bin/worktree remove <NNN>` once the PR is
  merged. The branch is left intact (it may have unpushed commits or an open PR).
- **Re-entry is idempotent.** Re-running `create` on a ready worktree just
  re-prints its path; an interrupted bootstrap resumes on the next `create`.

## Notes & limits

- Dependencies are installed per worktree (isolation over speed). The first
  worktree pays the Sail image build; later ones reuse the cached image.
- Running the **full** coverage gate in several worktrees at once is bound by
  the memory you give Docker/OrbStack — PHPStan is memory-hungry, so give the
  VM enough headroom (≈2-3 GB per concurrent gate) if you run many at once.
- `vendor/` bootstraps through `laravelsail/php84-composer` (the highest
  composer image Sail publishes; `composer.json` only needs php `^8.3`), then
  the authoritative `composer install` runs inside the php8.5 app container.
  Override with `WORKTREE_COMPOSER_IMAGE` if Sail later ships a php85 image.
