---
paths:
  - "**/*.php"
---

# PHP

## Automated Refactoring (Rector)

- **Rector is the automated-fix layer for structural PHP**, complementing Pint (style) and PHPStan (detection). It enforces our conventions (explicit return types, type hints, readonly, early returns, dead-code removal, PHP/Laravel modernization) by rewriting the code, and its config lives in `rector.php` (scoped to the same paths PHPStan analyzes, plus `tests/`).
- **`composer refactor` applies fixes; `composer refactor:check` is the dry-run.** Think of `composer refactor` as the semantic counterpart to Pint's `composer lint` formatter — run it to auto-apply structural fixes before pushing.
- **The backend gate runs Rector.** `./vendor/bin/sail composer test` also runs `rector process --dry-run` (via `refactor:check`), and CI runs it too. A failing dry-run fails the build — run `./vendor/bin/sail composer refactor` to apply the suggested changes, review the diff, and re-run the gate before pushing.
