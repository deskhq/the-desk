---
paths:
  - "resources/js/**"
  - "**/*.vue"
  - "**/*.ts"
  - "app/Data/**/*.php"
  - "app/Enums/**/*.php"
---

# Frontend (Vue / TypeScript)

## Code Comments (JS/TS) — no redundant inline `//`

- **The PHP rule (`Prefer PHPDoc blocks over inline comments…`) has a JS/TS counterpart: don't litter function bodies with narrating inline `//` comments.** A comment that restates what the code already says (`// loop over messages` above a `for`, `// increment counter`, `// return the result`) is noise — it hides the signal, and it rots when the code changes and the comment doesn't. Delete it; let the names and the code speak.
- **Promote, don't inline-document.** A comment that documents a *declaration* — a prop, an emit, an interface/type member, a function, or an exported symbol — belongs in a JSDoc/TSDoc `/** … */` block directly above that declaration (editors and Volar surface it on hover), not a loose `//` line. When you keep such a comment, convert it to a `/** … */` block. Reserve bare `//` for explaining a statement or branch *inside* a body.
- **Keep the comments that earn their place:** ones that explain a non-obvious *why*, an intent, an edge case, an ordering constraint, or a workaround the code alone can't convey. When in doubt about a *why*-comment, keep it — the target is the redundant *what*, not genuine explanation.
- **Leave existing doc-comments alone.** JSDoc/TSDoc blocks (`/** … */`) are documentation, not inline noise — keep them, the same way PHPDoc blocks are kept on the PHP side.

## Generated TypeScript Types

- **Prefer the generated `App.Data.*` / `App.Enums.*` ambient types over hand-duplicating a DTO or enum in `@/types`.** They are produced from the PHP `Data` classes and enums by `spatie/laravel-typescript-transformer` (configured in `app/Providers/TypeScriptTransformerServiceProvider.php`), so the frontend stays in lockstep with the backend shape. Example: `type CustomEmoji = App.Data.CustomEmojiData`.
- **`resources/js/generated/generated.d.ts` is a git-ignored build artifact**, not source. Regenerate it with `./vendor/bin/sail artisan typescript:transform` after adding or changing any `Data` class or enum — otherwise `vue-tsc` fails with `TS2503: Cannot find namespace 'App'`.
- **CI regenerates it before type-checking** (the `Generate TypeScript Types` step in `.github/workflows/tests.yml`), so a fresh checkout with no `generated.d.ts` still passes — never assume the file pre-exists.

## Tooling

- **Never run `npm` on the host** — `node_modules` is installed inside the Linux container, so its native bindings (`@unrs/resolver-*`, `@rolldown/binding-*`) are Linux-only and a host run fails with `Cannot find native binding`. Always `./vendor/bin/sail npm run <script>`.
- `vue-tsc` (`npm run types:check`) doesn't use those native bindings, so it can run on the host, but prefer Sail for consistency.
- Use `sail npm run lint` / `format` (the write variants) to auto-fix what `lint:check` / `format:check` report.
- **`./vendor/bin/sail npm run test:js` is the Vitest suite**, covering `resources/js/composables`, `components`, `lib`, `theme`, and the custom `eslint-rules` — the layer the PHP `--min=100` coverage gate cannot reach. It needs no database, Reverb, or browser and runs in seconds. CI runs it in the `ci` job of `.github/workflows/tests.yml`, ahead of the PHP suite so a frontend regression fails fast. It is deliberately **not** part of `composer test` (which stays the backend gate and must not require a Node install); run both gates at once with `./vendor/bin/sail composer ci:check`.
- Use Wayfinder route functions (`@/actions/`, `@/routes/`), never hardcoded URLs.
