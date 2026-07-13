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

## Pre-flight — local CodeRabbit review (CLI)

Before you open the PR, run a **local** CodeRabbit review and fix what's worth fixing, so the PR opens clean. The CLI (`coderabbit`, alias `cr`; installed at `~/.local/bin` — ensure it's on PATH with `export PATH="$HOME/.local/bin:$PATH"`) reviews your local diff and, unlike the GitHub app on this OSS repo, respects the working-tree `.coderabbit.yaml` immediately.

Review this branch's changes against `master`, emitting structured findings:

```bash
coderabbit review --agent --base master
```

- `--agent` emits findings meant for you to act on; `--type all` (default) covers committed + uncommitted. Add `-c CLAUDE.md` to feed conventions as extra instructions if a review looks off. `--light` is a faster, lower-context pass.
- If auth has expired it will say so — the **user** must run `coderabbit auth login` (interactive browser); you cannot complete it. Surface that and pause.
- Free OSS tier is **rate-limited**; if you hit a limit, note it and lean on the app's PR review instead.

**Judge, then apply** — you decide, this is not blind auto-apply:

1. Read each finding.
2. Apply the correct, safe ones. **Skip** false positives and anything that conflicts with `CLAUDE.md` (e.g. hardcoding copy instead of `$t`/`__()`, dropping a type hint, bypassing Sail, touching a release-please-owned file). Note why you skipped.
3. Re-run the gate after fixes — backend `./vendor/bin/sail composer test`, frontend `lint:check`/`format:check`/`types:check`/`build`. A CodeRabbit fix still has to clear 100% coverage + Rector/PHPStan/Pint.
4. Re-run `coderabbit review --agent --base master` until it's clean or only nits you've consciously declined remain.
5. Then commit, push, and open the PR. Report to the user what you applied and what you skipped (with reasons).

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

## After opening — CodeRabbit app backstop

The **CodeRabbit GitHub app** still auto-reviews every PR as an always-on backstop (config in `.coderabbit.yaml`) and runs the Conventional-Commit **pre-merge title check** (`mode: error`), which *flags* a non-conventional title on the `CodeRabbit` check — a warning net under the one rule above. Note it does **not hard-block the merge**: `CodeRabbit` isn't one of the branch ruleset's required status checks (`ci`, `browser`, `commitlint`, `quality`), and `commitlint` validates commits, not the PR title. To make the title check actually gate merges, add `CodeRabbit` to the ruleset's required checks. You do **not** need to poll the app in-session: the local CLI pass (see "Pre-flight" above) is the primary review loop, and it already ran before the PR opened. Two things worth knowing:

- On this **OSS repo the app only loads `.coderabbit.yaml` from the base branch** (`master`) for security, so config changes take effect only after they merge — the app cannot be config-tested on a PR. The CLI has no such restriction; it reads the working-tree config.
- If the app later flags something genuinely real on the PR that the CLI missed, apply it with the same judgment (skip false positives / anything fighting `CLAUDE.md`), re-run the gate, and push.

## Never

- Hand-edit `CHANGELOG.md`, `VERSION`, or `.release-please-manifest.json` — release-please owns them.
- Rely on the branch name or an individual commit message to carry the changelog entry — only the PR title reaches release-please on a squash merge.
- Blindly apply every CodeRabbit suggestion. It produces false positives; a suggestion that fights `CLAUDE.md` loses.
