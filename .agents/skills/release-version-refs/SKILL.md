---
name: release-version-refs
description: Keep hardcoded release-version strings (git checkout vX.Y.Z, ghcr.io/emmpaul/the-desk:X.Y.Z image pins, "as of vX.Y.Z" prose) from drifting. release-please only stamps the new version into lines carrying an x-release-please annotation in a file registered under extra-files. Use whenever a change adds or edits a quick-start / install / upgrade step, an image-pin example, an .env example, or any operator-facing sentence that names a released version — before opening the PR.
---

Stop hardcoded version strings from silently going stale.

## Why this exists

When release-please cuts a release it bumps `.release-please-manifest.json` and `CHANGELOG.md`, and it stamps the new version into **operator-facing docs** — but **only** into lines that carry an `x-release-please-*` annotation **and** live in a file registered under `extra-files` in `release-please-config.json`. Anything else is invisible to it and drifts the moment a release ships. That is exactly how the README once told operators to `git checkout v1.0.0` while the manifest was already at `1.2.0` (issue #345).

So the rule is mechanical: **a new hardcoded release-version string that is not annotated, or whose file is not registered, will drift.** This is a release-hygiene skill, sibling to `open-pr` — run through it before the PR goes up.

## The rule

Any new **display** of a released version — `git checkout vX.Y.Z`, an `APP_IMAGE=ghcr.io/emmpaul/the-desk:X.Y.Z` pin, a `.env` example, prose like "as of vX.Y.Z" — must:

1. **Carry an annotation** on its line so release-please rewrites the version, and
2. **Live in a file registered** under `extra-files` in `release-please-config.json`.

Scope is version **display** strings only. **Do not** annotate dependency ranges, lockfile versions, GitHub Action SHA-pin `# vX.Y.Z` comments, or `CHANGELOG.md` — release-please owns the changelog and the manifest, and dep versions are not releases of this app.

## How the annotations work

release-please's **generic** updater scans each registered file line by line. On any line containing one of these markers it replaces the **first** semver on that line with the newly released version:

- `x-release-please-version` → full `X.Y.Z`
- `x-release-please-major` → the major only
- `x-release-please-minor` → `X.Y`

The marker just has to appear somewhere on the line; surrounding text is ignored, and only the numeric semver is rewritten (a `v` prefix or `**` bold markers stay put). Pick the form that matches the file's comment syntax:

- **Shell / YAML / `.env` (a `#` comment line):** put the marker in the trailing comment.
  ```bash
  git checkout v1.2.0 # x-release-please-version         (the desired release tag)
  echo 'APP_IMAGE=ghcr.io/emmpaul/the-desk:1.2.0' >> .env # x-release-please-version
  ```
- **Markdown prose (no code comment):** append an HTML comment, which renders invisibly.
  ```markdown
  As of **v1.2.0**, The Desk does not yet ship attachments. <!-- x-release-please-version -->
  ```

Because only the **first** semver on the line is replaced, keep exactly one version per annotated line. For a multi-version block, use the block form instead:

```
<!-- x-release-please-start-version -->
... one or more version strings ...
<!-- x-release-please-end -->
```

## Registering the file

Every annotated file must appear under `extra-files` in `release-please-config.json`. Use the explicit generic form so the annotation scanner runs regardless of file extension (a bare string path picks an updater by extension and can skip your annotations in `.yml`/`.json` files):

```json
"extra-files": [
  { "type": "generic", "path": "README.md" }
]
```

If you add a version reference to a brand-new file, add that file here too.

## Before opening the PR — grep the diff

Catch any un-annotated version string you (or a sibling edit) introduced:

```bash
# Version displays added in this branch that are NOT annotated:
git diff master... -- . ':(exclude)CHANGELOG.md' \
  | grep -E '^\+' \
  | grep -E 'v[0-9]+\.[0-9]+\.[0-9]+|the-desk:[0-9]+\.[0-9]+' \
  | grep -v 'x-release-please'
```

Every hit is either something to annotate + register, or a false positive to consciously skip (an Action SHA-pin comment, a dependency version, changelog text). Then confirm every annotated file is registered:

```bash
grep -rlE 'x-release-please' --include='*.md' --include='*.yml' --include='*.env*' . \
  | grep -v node_modules
# → each of these paths must appear under extra-files in release-please-config.json
```

## Verify

- **Config is valid JSON:** `python3 -m json.tool release-please-config.json > /dev/null`.
- **Dry-run the release PR** to see the stamped lines (needs a token/network; skip if unavailable): `npx release-please release-pr --dry-run --repo-url emmpaul/the-desk --config-file release-please-config.json --manifest-file .release-please-manifest.json`. Or just inspect the next release PR release-please opens — it should rewrite every annotated line with no manual edits.
- **Docs still build** if you touched `docs/`: `cd docs && npm run build`.

## See also

- `open-pr` — the other release-hygiene skill; the PR **title** is the Conventional Commit release-please reads on a squash merge.
- `CLAUDE.md` → "Commits & PR titles" and "Self-Hosting Documentation" — never hand-edit `CHANGELOG.md` / `VERSION` / `.release-please-manifest.json`; keep operator docs in sync in the same PR.
