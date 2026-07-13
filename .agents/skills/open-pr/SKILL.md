---
name: open-pr
description: Open (or retitle) a GitHub pull request in this repo the way release-please needs. This repo squash-merges with the PR title as the squash commit subject, so release-please reads the PR title — it MUST be a valid Conventional Commit or the change is silently dropped from CHANGELOG.md and the version bump. Use whenever creating, retitling, or preparing a pull request, or when the user says "open a PR", "raise a PR", "gh pr create".
---

Open a pull request that release-please can act on.

## The one rule that bites here

The repo **merges by squash only**, and `squash_merge_commit_title = PR_TITLE` (verify with `gh api repos/{owner}/{repo} --jq .squash_merge_commit_title`). So **the PR title becomes the commit on `master`** that release-please parses. A PR titled like a sentence ("Edit last message from the composer") is **silently dropped from `CHANGELOG.md` and the version bump**. `commitlint` does **not** catch this — it validates the PR's individual commits, not the PR title — so passing CI is no guarantee. The PR title is the thing that must be right.

## Before opening

1. **Not on `master`.** Work is on a feature branch; if not, branch first. Branch names are cosmetic (release-please ignores them) — mirror the type if you like (`feat/…`, `fix/…`), but the title is what matters.
2. **The gate is green.** Don't open a PR on a red gate. Backend: `./vendor/bin/sail composer test` (Pint + PHPStan + Rector + `--min=100` coverage). Frontend: `./vendor/bin/sail npm run lint:check`, `format:check`, `types:check`, `build`. See `CLAUDE.md`.
3. **Companion updates done** where the change requires them: `lang/fr.json` for new user-facing copy, `docs/` for operator-facing changes, tests for every change.
4. **Branch pushed**: `git push -u origin <branch>`.

## The PR title — a valid Conventional Commit

Format: `type: imperative subject` — lowercase `type`, a colon-space, a concise imperative subject, **no trailing period**, aim for ≤ ~70 chars. Optional scope: `type(scope): …`. No Claude/Co-Authored-By attribution anywhere (see the global `CLAUDE.md`).

Pick `type` from what the diff actually ships:

| Type | Use for | In changelog? | Version bump |
| --- | --- | --- | --- |
| `feat` | a user-facing capability | ✅ Features | minor |
| `fix` | a bug fix | ✅ Bug Fixes | patch |
| `perf` | a performance improvement | ✅ Performance | patch |
| `refactor` | internal restructure, no behaviour change | ✅ Code Refactoring | patch |
| `docs` | docs / guidance only | ❌ | none |
| `test` | tests only | ❌ | none |
| `chore` / `ci` / `build` | tooling, CI, deps, build | ❌ | none |
| `style` | formatting only | ❌ | none |

(The changelog sections come from `release-please-config.json` — check it if unsure.) A **breaking change** uses `type!: …` (e.g. `feat!:`) or a `BREAKING CHANGE:` footer, forcing a major bump.

If a change is a user-facing feature or fix, it must ship under `feat`/`fix` (or `perf`/`refactor`) — otherwise it won't appear in the release notes. Only use a non-changelog type when the PR genuinely ships no user-facing feature or fix.

## The PR body

- Reference the issue: `Closes #NNN` (or `Refs #NNN`).
- What changed and why; how it was tested; any i18n/docs updates.
- Never add a `Co-Authored-By` trailer or other Claude/Anthropic attribution.

## Open it, then verify

```bash
gh pr create --title "feat: <imperative subject>" --body "$(cat <<'EOF'
Closes #NNN.

<what / why / testing>
EOF
)"
```

Then confirm the title is conventional before you consider the PR done:

```bash
gh pr view <n> --json title --jq .title
```

Ask yourself: does it start with a valid `type` (or `type!`) then `: `? Would release-please file it under the right section? If not, fix it now with `gh pr edit <n> --title "…"` — and remember GitHub appends ` (#<n>)` to the squash subject at merge time, which is fine.

## Never

- Hand-edit `CHANGELOG.md`, `VERSION`, or `.release-please-manifest.json` — release-please owns them.
- Rely on the branch name or an individual commit message to carry the changelog entry — only the PR title reaches release-please on a squash merge.
