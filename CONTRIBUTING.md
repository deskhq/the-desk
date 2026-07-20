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

1. Branch off `master` (e.g. `feat/message-reminders`, `fix/session-index`).
2. **Title the PR as a Conventional Commit** — see below.
3. Keep the PR focused on a single concern; reference the issue it closes.
4. Make sure both quality gates pass locally.
5. Fill in what changed and why, and how you tested it.

### The PR title is the release entry

PRs are squash-merged with the **PR title as the commit subject**, so the title —
not your individual commits — is what [release-please](https://github.com/googleapis/release-please)
reads to build `CHANGELOG.md` and pick the next version. A title like
`Add message reminders` parses as nothing and is dropped from the release
silently, so the title is validated by its own required check (the `pr-title` job
in `.github/workflows/commitlint.yml`) using the same types and subject rules as
commitlint above: `type(optional-scope): imperative subject`, with a lowercase
type, no capital letter opening the subject, and no trailing period — e.g.
`feat(messaging): add message reminders`. Editing the title re-runs the check, so
a rejected title turns green without a new commit.

A maintainer will review and merge. Thanks for helping make The Desk better!
