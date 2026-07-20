# Contributing to The Desk

Thanks for taking the time to contribute! This project is open source (MIT) and
contributions of all kinds are welcome — bug reports, docs fixes, and code.

## Ways to contribute

- **Report a bug** or request a feature by [opening an issue](https://github.com/emmpaul/the-desk/issues).
  Search existing issues first to avoid duplicates.
- **Improve the docs** — the operator/self-hosting docs live in `docs/` (an Astro
  Starlight site); everything else is inline in the codebase.
- **Send a pull request** — see the workflow below.

## Development setup

Local development runs on [Laravel Sail](https://laravel.com/docs/sail) (Docker).
See the [Development section of the README](README.md#development) for the full
first-run steps. In short:

```bash
git clone git@github.com:emmpaul/the-desk.git
cd the-desk
cp .env.example .env
composer install
./vendor/bin/sail up -d
./vendor/bin/sail composer setup
```

> **Run all Node/npm tooling through Sail** (`./vendor/bin/sail npm run …`), not
> bare `npm` on your host — `node_modules` is installed inside the Linux
> container and its native bindings are Linux-only.

## Before you push: the quality gates

Both gates must pass. CI runs them too, so a red gate blocks the merge.

**Backend** — runs Pint (style), PHPStan, Rector (dry-run), and the test suite at
**100% coverage** (non-negotiable):

```bash
./vendor/bin/sail composer test
```

If Rector reports pending changes, apply them with `./vendor/bin/sail composer refactor`,
review the diff, and re-run. Fix any Pint issues with `./vendor/bin/sail composer lint`.

**Frontend** — ESLint, Prettier, `vue-tsc`, and the build:

```bash
./vendor/bin/sail npm run lint:check
./vendor/bin/sail npm run format:check
./vendor/bin/sail npm run types:check
./vendor/bin/sail npm run build
```

Auto-fix with `./vendor/bin/sail npm run lint` and `./vendor/bin/sail npm run format`.

## Coding conventions

- **Test-driven.** Every change must be programmatically tested — write or update
  a [Pest](https://pestphp.com) test (red → green → refactor). The suite is gated
  at 100% coverage.
- **Never hardcode user-facing copy.** All user-visible strings go through the
  translation layer (`$t(...)` / `__(...)`); add new keys to `lang/fr.json` (and
  any other locale). The message key *is* the English source string.
- **Keep the docs in sync.** If a change adds or alters anything operator-facing
  (a new `.env`/config setting, install/upgrade steps, the production stack),
  update the relevant page under `docs/` in the same PR.
- **Follow the surrounding code.** Match existing structure, naming, and idioms;
  check sibling files before introducing a new pattern. Use Laravel's `artisan
  make:` generators for new files.
- **Found an unrelated bug?** Don't fix it inline — open a separate issue so the
  current change stays focused (unless it directly blocks your work).

## Commit messages

This repo enforces [Conventional Commits](https://www.conventionalcommits.org/)
via commitlint in CI. Use a lowercase type and subject:

```
type(optional-scope): short summary in lowercase

feat(messaging): add message reminders
fix(a11y): give the composer an accessible name
docs: clarify reverse-proxy TLS setup
```

Common types: `feat`, `fix`, `docs`, `refactor`, `test`, `chore`, `ci`. Keep the
subject imperative and under ~72 characters; it should **not** start with a
capital letter (commitlint rejects sentence-case subjects).

## Pull requests

1. Branch off `develop` (e.g. `feat/message-reminders`, `fix/session-index`), and
   target `develop` — see [Releases](#releases) below. The one exception is a
   [hotfix](#hotfixes-releasing-when-develop-is-not-releasable) for a broken
   production release, which branches off and targets `master`.
2. **Title the PR as a Conventional Commit** — see below.
3. Keep the PR focused on a single concern; reference the issue it closes.
4. Make sure both quality gates pass locally.
5. Fill in what changed and why, and how you tested it.

### The PR title is the release entry

Feature PRs are squash-merged with the **PR title as the commit subject**, so the
title — not your individual commits — is what
[release-please](https://github.com/googleapis/release-please) reads to build the
release notes and pick the next version. A title like `Add message reminders`
parses as nothing and is dropped from the release silently, so the title is
validated by its own required check (the `pr-title` job in
`.github/workflows/commitlint.yml`) using the same types and subject rules as
commitlint above: `type(optional-scope): imperative subject`, with a lowercase
type, no capital letter opening the subject, and no trailing period — e.g.
`feat(messaging): add message reminders`. Editing the title re-runs the check, so
a rejected title turns green without a new commit.

That squash subject lands on `develop`, where it becomes one entry in the next
release candidate, and reaches `master` — and so `CHANGELOG.md` — when `develop`
is promoted.

A maintainer will review and merge. Thanks for helping make The Desk better!

## Releases

Two branches, two release lines, both cut by
[release-please](https://github.com/googleapis/release-please) from the
Conventional Commit history:

| Branch | Cuts | Example tag | Docker tags |
| --- | --- | --- | --- |
| `develop` | Release candidates | `v1.12.0-rc.0` | `1.12.0-rc.0`, and the moving `rc` |
| `master` | Stable releases | `v1.12.0` | `1.12.0`, `1.12`, and the moving `latest` |

Work flows in one direction: a feature PR merges into `develop`, which cuts
candidates as it accumulates changes; when a version is ready, `develop` is
promoted to `master`, which cuts the stable release. Pushing to `master` also
moves the `edge` image tag.

**Nothing is tagged by a push.** On each line, release-please maintains a
*release PR* — a running proposal for the next version, listing the changes it
would include and updating itself as more land. That PR is the release: merging
it is what writes the version, tags it, and publishes the image. Pushing to
`develop` or `master` only updates the proposal.

So a release is two merges, not one:

1. Merge the feature PR into `develop`. release-please opens or updates the
   candidate release PR (`release-please--branches--develop`).
2. Merge that release PR when you want a candidate. Now `v1.12.0-rc.0` is
   tagged, published, and marked as a pre-release on GitHub.

Promoting works the same way: merge `develop` into `master`, then merge the
stable release PR (`release-please--branches--master`) to cut `v1.12.0`. The two
release PRs are independent and cannot collide — release-please names its branch
after the line it targets.

A release PR that never gets merged is not a failure state; it just means the
next version has not been cut yet.

**Promote `develop` to `master` with a merge commit, never a squash.** Everything
else in this repo is squash-merged, and that is exactly the problem here:
release-please reads the individual Conventional Commits, so squashing a
promotion would collapse an entire release worth of `feat:`/`fix:` subjects into
one, and the changelog would lose every entry but that one.

### Why the two lines cannot contaminate each other

release-please reads its config *and* its version manifest from the branch it
targets, so each line gets its own pair:

| | Stable (`master`) | Candidate (`develop`) |
| --- | --- | --- |
| Config | `release-please-config.json` | `release-please-config.develop.json` |
| Manifest | `.release-please-manifest.json` | `.release-please-manifest.develop.json` |

They are **separate files, not divergent copies of one file**. That distinction
is the whole safety property: a merge in either direction carries both pairs
across unchanged, so a promotion can never move the `prerelease` flag onto the
stable config and turn a stable release into a candidate. Nothing about
`.github/workflows/release-please.yml` differs per branch either — one file
contains both jobs, and the pushed branch decides which one runs.

Two consequences worth knowing:

- **The candidate config declares no `extra-files`**, so an rc bump never stamps
  `1.12.0-rc.0` into `VERSION`, `.env.prod.example`, `docker/install.sh`, or the
  docs pages that quote a version. Those lines only ever carry stable versions.
- **The candidate config sets `skip-changelog`**, so `CHANGELOG.md` belongs to
  `master` alone. Candidates still get full release notes on their GitHub
  release; they just don't write them to the file.

These invariants are enforced by `tests/Unit/ReleaseFlowTest.php` rather than by
convention — change the release setup and that file will tell you what broke.

After each stable release, a job moves `develop`'s candidate baseline onto the
version just released, so the next feature there starts a fresh `-rc.0` series
instead of incrementing the previous one forever. It arrives as a small pull
request rather than a push, because `develop` — like `master` — only accepts
changes through one; the job asks for auto-merge, so unless the repository has it
turned off the PR merges itself once the checks pass.

### Hotfixes: releasing when develop is not releasable

Promotion is all-or-nothing. If `master` is at `1.12.0` and `develop` is carrying
three half-finished features, a one-line fix routed through `develop` cannot ship
without shipping those features too. For that case — and **only** that case —
there is a second path that releases straight from `master`.

**The bar is deliberately high. Both of these must hold:**

- a released version is broken in production (or has a security hole), **and**
- `develop` cannot be promoted as it stands.

If either is false, use the normal flow. Anything that can wait for the next
candidate — which is nearly everything, including most `fix:` work — goes through
`develop`. A `master`-first route that gets used for convenience is how `develop`
becomes vestigial again, which is the problem the two-line setup was introduced
to solve.

When it does apply:

1. **Branch off `master`**, not `develop`.
2. **Open the PR against `master`.** It is squash-merged like any other, so the
   title is still the release entry — `fix:` here, which cuts a patch.
3. **Merge the stable release PR** release-please opens. That tags `1.12.1` and
   publishes it; `sync-candidate-baseline` moves develop's baseline onto it as
   usual.
4. **Merge `master` back into `develop`.**

#### Why step 4 is not optional

The fix now exists only on `master`, and `develop` carries the broken version of
those files. A merge-commit promotion does not throw the fix away by itself —
git sees it as a change only `master` made and keeps it — so the failure is
quieter than that, and comes in three forms:

- **Work built on the wrong code.** Anything written on `develop` that touches
  those files is written against the defect, so a later promotion can reintroduce
  it, or land as a conflict that has to be resolved by someone reconstructing the
  hotfix from memory.
- **A promotion that isn't a merge commit.** Squash the promotion — or rebuild
  those files wholesale from `develop` — and `develop`'s tree wins outright. The
  fix is gone, and nothing in the diff says why production broke a second time.
- **Divergence that grows.** Every release after an un-back-merged hotfix starts
  further from `develop`, and each promotion carries more of that gap.

The back-merge closes all three at once, and it is not left to memory: every
stable release runs the `backmerge` job, which opens a `master` → `develop` pull
request whenever `master` carries commits `develop` does not. After a normal
promotion there is nothing to send back and the job does nothing; after a hotfix
it leaves an open PR. If one is already open, it tracks `master` rather than
being duplicated.

**Merge that PR with a merge commit, never a squash** — for the same reason a
promotion is never squashed, plus a sharper one: a squash would flatten `master`'s
history into a single new commit that shares no ancestry with it, so git stops
seeing the two branches as related and later merges duplicate changes or conflict
instead of fast-forwarding past them.

A hotfix does not need a candidate of its own. The back-merge puts it on
`develop`, and the next candidate cut there includes it.
