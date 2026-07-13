---
name: update-deps
description: Refresh this repo's Composer and npm dependencies safely. Default is an in-range refresh (lockfiles only, respecting the caret ranges); an explicit majors mode bumps constraints to new majors one package at a time. Everything runs through Sail and is verified against the real quality gate. Use when the user says "update deps", "update dependencies", "bump composer/npm packages", or "update to latest majors".
---

Update dependencies without landing a red gate.

## What "update" means (two modes)

- **In-range refresh (default).** `composer update` + `npm update` that respect the existing caret ranges. Bumps `laravel/framework` from `13.17.x` to the newest `13.x`, never to `14.x`. Only lockfiles change; `composer.json`/`package.json` stay put. This is routine hygiene — keep it boring.
- **Majors mode (explicit only).** Raise the version constraints in the manifests to pull in new majors (`^13` → `^14`). Higher-risk, can involve breaking changes. Only run this when the user explicitly asks for "latest majors" / "upgrade". See [Majors mode](#majors-mode).

## Ecosystems

Default target is the two **app** ecosystems, both run **through Sail**:

- **Composer** — `./vendor/bin/sail composer …`
- **root npm** (`package.json`) — `./vendor/bin/sail npm …`

The **`docs/`** Starlight project is a separate, host-installed `node_modules` excluded from every app gate. Update it **only when explicitly asked** — and there it runs on the host, not Sail (`cd docs && npm install && npm run build`).

Never run bare `npm` on the host for the app: `node_modules` is Linux-only in the container (native bindings). Bare npm fails with `Cannot find native binding`.

## Preconditions — stop if any fail

1. **Clean working tree.** If there are uncommitted changes, stop and say so. Do **not** stash.
2. **Fresh baseline.** `git checkout master && git pull`, then branch off up-to-date `master`:
   `git checkout -b deps/<yyyy-mm-dd-or-topic>`. A dep refresh must sit on the newest `master`, not on an in-flight feature branch.
3. **Sail is up.** If the containers aren't running, print `./vendor/bin/sail up -d` and halt — don't start containers yourself.

## In-range refresh (default flow)

1. Refresh both ecosystems:
   - `./vendor/bin/sail composer update`
   - `./vendor/bin/sail npm update`
   Note: `composer update` fires `post-update-cmd` (`vendor:publish`, `boost:update`), which can itself change tracked files — so the backend gate must run whenever `composer.lock` moves.
2. **Verify — scope-aware** (see [Verification](#verification)). Only gate the ecosystem(s) that actually changed.
3. Handle any red gate (see [When the gate goes red](#when-the-gate-goes-red)).
4. On green: **audit** (see [Security audit](#security-audit)), then **commit** (see [Deliverable](#deliverable)).

## Majors mode

Only when explicitly requested. Goal: attributable breakage.

1. Enumerate outdated **direct** deps:
   - `./vendor/bin/sail composer outdated --direct`
   - `./vendor/bin/sail npm outdated` (top-level only)
   If the user named specific packages, narrow to those.
2. Bump **one package at a time** — raise its constraint in the manifest, `composer update <pkg>` / `npm install <pkg>@latest`, then run the relevant gate.
3. If that package's bump is green → keep it, move to the next.
4. If it breaks with a **real** failure → **revert just that one package's bump**, file an issue (see below), and continue with the remaining packages. Never mass-bump and gate once — "something in this 12-package bump broke coverage" is not an actionable issue; "`spatie/laravel-data` v5 broke these 3 tests" is.

## Verification

Run the **real gate**, scoped to what changed:

- **Composer changed** (`composer.lock` moved) → backend gate:
  `./vendor/bin/sail composer test` (Pint + PHPStan + Rector + `--min=100` coverage).
- **npm changed** (`package-lock.json` moved) → frontend gate, each via Sail:
  `npm run lint:check`, `npm run format:check`, `npm run types:check`, `npm run build`, `npm run test:js`.
- The **untouched** ecosystem's gate is skipped.

An in-range refresh usually touches both, so this often runs the full gate anyway — the scoping only saves time when a single lockfile moved.

## When the gate goes red

Two tiers:

1. **Mechanical fixes** — apply unconditionally, then re-gate. These are fixes the gate already owns and a dep bump can legitimately shift formatting:
   - `./vendor/bin/sail composer refactor` (Rector auto-apply)
   - `./vendor/bin/sail npm run lint` and `./vendor/bin/sail npm run format` (ESLint/Prettier write)
2. **Real breakage** — a failing test, a type error, a behavioural change. **Stop rewriting app code** and:
   - In-range mode: report the failure; don't silently patch app code to chase a dependency.
   - Majors mode: revert just that one package's bump and continue with the rest.
   - **Either mode — file an issue** (see below).

### Filing the issue (on any real breakage)

Follow the repo's "Reporting Bugs Found While Doing Something Else" convention:

1. Check for a dup first: `gh issue list --state open --search "<package> <keywords>"` (and closed too).
2. If none, `gh issue create` with a fitting label (`dependencies`). Body: which package(s), the gate output, how it surfaced, and clear acceptance criteria for the fix.
3. Use the **`emmpaul`** gh account (this repo only uses `emmpaul`, not `emmpauldev` — switch before `gh`).
4. Report the issue number back to the user.

## Security audit

After a successful update, run both audits and append remaining advisories to the summary — **informational only**, do not force a major bump to clear a CVE:

- `./vendor/bin/sail composer audit`
- `./vendor/bin/sail npm audit`

If clearing an advisory needs a major, surface it and let the user decide.

## Deliverable

On green, commit the work and **open a PR** — the flow runs all the way through to an open deps PR.

- **One commit per ecosystem** (independently revertable; squash merge flattens them anyway):
  - `deps(composer): update composer dependencies`
  - `deps(npm): update npm dependencies`
- `deps` is our **changelog type for dependency bumps**. release-please folds it into the release's **Dependencies** section, and commitlint's `type-enum` allows it. It is **non-bumping**: a deps-only PR never cuts a release on its own — it rides along into the next `feat`/`fix` release. Never fall back to `chore(deps):` — that type is invisible to the changelog.
- Never add a `Co-Authored-By` trailer or any Claude/Anthropic attribution.
- **Open one combined PR** covering both ecosystems, titled `deps: update composer and npm dependencies` (squash merge makes the PR title the single changelog line under Dependencies). Hand off to the `open-pr` skill to open it, or `gh pr create` directly with that Conventional title. Reference any issues filed in the body.
- Report a concise **before → after** summary of what moved (package, old version, new version), plus the audit results and any issues filed.
