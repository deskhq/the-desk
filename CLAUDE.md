<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.5
- inertiajs/inertia-laravel (INERTIA_LARAVEL) - v3
- laravel/fortify (FORTIFY) - v1
- laravel/framework (LARAVEL) - v13
- laravel/prompts (PROMPTS) - v0
- laravel/reverb (REVERB) - v1
- laravel/sanctum (SANCTUM) - v4
- laravel/scout (SCOUT) - v11
- laravel/socialite (SOCIALITE) - v5
- laravel/wayfinder (WAYFINDER) - v0
- larastan/larastan (LARASTAN) - v3
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- laravel/sail (SAIL) - v1
- pestphp/pest (PEST) - v4
- phpunit/phpunit (PHPUNIT) - v12
- rector/rector (RECTOR) - v2
- @inertiajs/vue3 (INERTIA_VUE) - v3
- tailwindcss (TAILWINDCSS) - v4
- vue (VUE) - v3
- @laravel/echo-vue (ECHO_VUE) - v2
- @laravel/vite-plugin-wayfinder (WAYFINDER_VITE) - v0
- eslint (ESLINT) - v9
- laravel-echo (ECHO) - v2
- prettier (PRETTIER) - v3

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

# Inertia v3

- Use all Inertia features from v1, v2, and v3. Check the documentation before making changes to ensure the correct approach.
- New v3 features: standalone HTTP requests (`useHttp` hook), optimistic updates with automatic rollback, layout props (`useLayoutProps` hook), instant visits, simplified SSR via `@inertiajs/vite` plugin, custom exception handling for error pages.
- Carried over from v2: deferred props, infinite scroll, merging props, polling, prefetching, once props, flash data.
- When using deferred props, add an empty state with a pulsing or animated skeleton.
- Axios has been removed. Use the built-in XHR client with interceptors, or install Axios separately if needed.
- `Inertia::lazy()` / `LazyProp` has been removed. Use `Inertia::optional()` instead.
- Prop types (`Inertia::optional()`, `Inertia::defer()`, `Inertia::merge()`) work inside nested arrays with dot-notation paths.
- SSR works automatically in Vite dev mode with `@inertiajs/vite` - no separate Node.js server needed during development.
- Event renames: `invalid` is now `httpException`, `exception` is now `networkError`.
- `router.cancel()` replaced by `router.cancelAll()`.
- The `future` configuration namespace has been removed - all v2 future options are now always enabled.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== wayfinder/core rules ===

# Laravel Wayfinder

Use Wayfinder to generate TypeScript functions for Laravel routes. Import from `@/actions/` (controllers) or `@/routes/` (named routes).

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- The `{name}` argument should not include the test suite directory. Use `php artisan make:test --pest SomeFeatureTest` instead of `php artisan make:test --pest Feature/SomeFeatureTest`.
- Run tests: `php artisan test --compact` or filter: `php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.

=== inertia-vue/core rules ===

# Inertia + Vue

Vue components must have a single root element.
- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

</laravel-boost-guidelines>

# Project Conventions

<!-- Custom project guidance below is preserved across `boost:update` runs. -->

## Commits & PR titles — Conventional Commits drive the release (non-negotiable)

- **Releases run on two branches.** `develop` accumulates features and cuts `vX.Y.Z-rc.N` **release candidates**; `master` cuts the stable releases and is fed by promoting `develop`. Feature work therefore branches off and targets **`develop`** — a PR opened against `master` bypasses the candidate line, and nothing errors to tell you. Full flow in `CONTRIBUTING.md` → *Releases*; the invariants are enforced by `tests/Unit/ReleaseFlowTest.php`.
- **Releases are automated by release-please** (`.github/workflows/release-please.yml`). Each line reads its own pair — `release-please-config.json` + `.release-please-manifest.json` on `master`, `release-please-config.develop.json` + `.release-please-manifest.develop.json` on `develop` — because release-please resolves both from the branch it targets. They are separate files, not divergent copies, so a merge cannot move the prerelease flag onto `master`. Never hand-edit `CHANGELOG.md`, `VERSION`, or either manifest — release-please owns them.
- **Nothing is tagged by a push.** release-please maintains a *release PR* on each line; merging that PR is what tags and publishes. A push only updates the proposal.
- **Feature PRs merge by squash, and the squash commit subject is the PR title** (`squash_merge_commit_title = PR_TITLE`). So **the PR title _is_ the commit release-please reads** — it MUST be a valid Conventional Commit (`type: imperative subject`, lower-case type, no trailing period), e.g. `feat: edit last message from the composer with ↑`. A PR titled like a sentence ("Edit last message…") is **silently dropped from the changelog and the version bump** — this is the #1 mistake here.
- **Hotfixes are the one exception, and only when both halves hold:** a released version is broken in production *and* `develop` cannot be promoted as it stands. Then branch off **`master`**, PR into `master` (title `fix:`, which cuts a patch), and merge the stable release PR. Everything else — including nearly all `fix:` work — goes through `develop`; do not take this route because it is faster. After the release, `master` **must** be merged back into `develop` with a merge commit. A merge-commit promotion would not drop the fix on its own, but `develop` is left holding the broken code: work built there reintroduces the defect or lands as a conflict, and any promotion that isn't a merge commit reverts the fix outright. The `backmerge` job opens that PR automatically after every stable release and queues auto-merge with `--merge` on it, so the merge method is not chosen by hand; the `integration-merge` workflow does the same for the `develop` → `master` promotion. Full path in `CONTRIBUTING.md` → *Hotfixes*.
- **Promote `develop` to `master` with a merge commit, never a squash.** Merge commits are enabled for that one purpose: release-please reads the individual Conventional Commits, so squashing a promotion would collapse a whole release's worth of them into a single changelog entry.
- **Changelog-relevant types** (from `release-please-config.json`): `feat` → Features (minor bump), `fix` → Bug Fixes (patch), `perf` → Performance, `refactor` → Code Refactoring, `deps` → Dependencies. A breaking change (`feat!:` / `fix!:`, or a `BREAKING CHANGE:` footer) forces a major bump. `deps` is the type for dependency bumps (the `update-deps` skill and dependabot both emit it): it appears under **Dependencies** but is **non-bumping**, so a deps-only batch never cuts a release on its own — it rides along into the next `feat`/`fix` release. Other Conventional types are allowed (`docs`, `test`, `chore`, `ci`, `build`, `style`) but **do not appear in the changelog and do not bump the version** — only use them for PRs that genuinely ship no user-facing feature/fix.
- **The title is enforced mechanically.** The `pr-title` job in `.github/workflows/commitlint.yml` (`amannn/action-semantic-pull-request`) fails the build on a non-Conventional title, and re-runs on `edited` so fixing the title turns it green without a new commit. Its type list and subject rules mirror `commitlint.config.mjs`, and `tests/Unit/PullRequestTitleCheckTest.php` fails if the two ever drift — so update both together, never one alone. In particular the subject must **not** start with a capital letter (that covers acronym-led subjects too: `feat: SSO login…` is rejected, `feat: re-check the SSO policy` is fine) and must not end with a full stop.
- **Keep individual commit messages Conventional too.** `commitlint` (config-conventional) runs on every PR and validates the PR's commits. It never reads the PR title — that is the `pr-title` job's job — but both must be right, since the title is the one that reaches release-please.
- **Branch names are cosmetic** (release-please ignores them). Mirror the type if you like (`feat/…`, `fix/…`), but never rely on the branch name for the changelog — set the PR title.
- **When opening a PR with `gh pr create`, always pass a Conventional-Commit `--title`** and reference the issue in the body (`Closes #NNN`).

## Implementing Issues (TDD)

- **Always activate the `tdd` skill when implementing an issue or building a feature.** Drive the work test-first (red → green → refactor): write a failing test that captures the acceptance criterion, make it pass with the minimal change, then refactor. This pairs with the non-negotiable 100% coverage gate below.

## Internationalization (i18n) — never hardcode user-facing copy

- **All user-visible copy must go through the translation layer — never hardcode English in a component, controller, or Blade view.** The message key *is* the English source string (matching Laravel's JSON translation convention), so a missing translation falls back to readable English.
- **Frontend (Vue):**
  - In templates, use the global `$t` helper (registered in `resources/js/app.ts`, available in every component with no import): `{{ $t('Save changes') }}`, `:placeholder="$t('Search messages')"`, `:aria-label="$t('Close')"`, `<Head :title="$t('Notifications')" />`.
  - In `<script setup>`, use `const { t } = useTranslations()` (from `@/composables/useTranslations`). For module-scope contexts like `defineOptions({ layout: { breadcrumbs: [...] } })`, import `translate` from `@/lib/i18n`.
  - Interpolate dynamic values with Laravel-style `:placeholder` tokens: `$t('Signed in as :name', { name: user.name })`.
  - Format dates, times, and numbers through the locale-aware helpers in `@/lib/datetime` and `@/lib/numbers` (they default to the active locale) — never call `toLocaleString`/`Intl` with a hardcoded locale.
  - Keep `data-test` selectors, route names, CSS classes, and other non-visible strings **out** of `$t` — translate only what a user reads.
- **Backend (PHP/Blade):** wrap user-facing strings in `__('...')` (flash messages, mailables, notifications, any string rendered to the user). Add new keys to the catalogs below.
- **Catalogs:** English needs no file (keys are English). Add every new key's translation to `lang/fr.json` (and any future locale). The per-user locale lives on `users.locale` (the `App\Enums\AppLocale` enum); `App\Http\Middleware\SetLocale` applies it and inlines the active catalog, and `LocaleCatalogController` serves catalogs for in-app language switching.
- When adding a new locale, add its enum case in `App\Enums\AppLocale`, its `lang/{locale}.json`, and nothing else — the plumbing picks it up automatically.

## Frontend Tooling (run through Sail)

- **Always run Node/npm tooling through Sail** — `./vendor/bin/sail npm run <script>`, never bare `npm` on the host. `node_modules` is installed inside the Linux container, so its native bindings (`@unrs/resolver-*`, `@rolldown/binding-*`) are Linux-only. Running `npm run build`, `lint`, etc. directly on macOS fails with `Cannot find native binding` (the npm optional-dependencies bug); Sail runs them in the matching Linux environment where the bindings exist.
- `vue-tsc` (`npm run types:check`) doesn't use those native bindings, so it can run on the host, but prefer Sail for consistency.
- The frontend quality gate is `./vendor/bin/sail npm run lint:check`, `format:check`, `types:check`, `test:js`, and `build` — all five must pass before pushing, and CI runs them too. Use `sail npm run lint` / `format` (the write variants) to auto-fix violations.
- **`test:js` is the Vitest suite** covering `resources/js/composables`, `components`, `lib`, `theme`, and the custom `eslint-rules` — the layer the PHP `--min=100` coverage gate cannot reach. It needs no database, Reverb, or browser and runs in seconds. It is not part of `composer test` (which stays the backend gate and must not require Node); run it with the frontend gate above, or run both at once with `./vendor/bin/sail composer ci:check`.

## Code Comments (JS/TS) — no redundant inline `//`

- **The PHP rule (`Prefer PHPDoc blocks over inline comments…`) has a JS/TS counterpart: don't litter function bodies with narrating inline `//` comments.** A comment that restates what the code already says (`// loop over messages` above a `for`, `// increment counter`, `// return the result`) is noise — it hides the signal, and it rots when the code changes and the comment doesn't. Delete it; let the names and the code speak.
- **Promote, don't inline-document.** A comment that documents a *declaration* — a prop, an emit, an interface/type member, a function, or an exported symbol — belongs in a JSDoc/TSDoc `/** … */` block directly above that declaration (editors and Volar surface it on hover), not a loose `//` line. When you keep such a comment, convert it to a `/** … */` block. Reserve bare `//` for explaining a statement or branch *inside* a body.
- **Keep the comments that earn their place:** ones that explain a non-obvious *why*, an intent, an edge case, an ordering constraint, or a workaround the code alone can't convey. When in doubt about a *why*-comment, keep it — the target is the redundant *what*, not genuine explanation.
- **Leave existing doc-comments alone.** JSDoc/TSDoc blocks (`/** … */`) are documentation, not inline noise — keep them, the same way PHPDoc blocks are kept on the PHP side.

## Generated TypeScript Types

- **Prefer the generated `App.Data.*` / `App.Enums.*` ambient types over hand-duplicating a DTO or enum in `@/types`.** They are produced from the PHP `Data` classes and enums by `spatie/laravel-typescript-transformer` (configured in `app/Providers/TypeScriptTransformerServiceProvider.php`), so the frontend stays in lockstep with the backend shape. Example: `type CustomEmoji = App.Data.CustomEmojiData`.
- **`resources/js/generated/generated.d.ts` is a git-ignored build artifact**, not source. Regenerate it with `./vendor/bin/sail artisan typescript:transform` after adding or changing any `Data` class or enum — otherwise `vue-tsc` fails with `TS2503: Cannot find namespace 'App'`.
- **CI regenerates it before type-checking** (the `Generate TypeScript Types` step in `.github/workflows/tests.yml`), so a fresh checkout with no `generated.d.ts` still passes — never assume the file pre-exists.

## Automated Refactoring (Rector)

- **Rector is the automated-fix layer for structural PHP**, complementing Pint (style) and PHPStan (detection). It enforces our conventions (explicit return types, type hints, readonly, early returns, dead-code removal, PHP/Laravel modernization) by rewriting the code, and its config lives in `rector.php` (scoped to the same paths PHPStan analyzes, plus `tests/`).
- **`composer refactor` applies fixes; `composer refactor:check` is the dry-run.** Think of `composer refactor` as the semantic counterpart to Pint's `composer lint` formatter — run it to auto-apply structural fixes before pushing.
- **The backend gate runs Rector.** `./vendor/bin/sail composer test` now also runs `rector process --dry-run` (via `refactor:check`), and CI runs it too. A failing dry-run fails the build — run `./vendor/bin/sail composer refactor` to apply the suggested changes, review the diff, and re-run the gate before pushing.

## Code Coverage

- **100% code coverage is required — this is non-negotiable.** The test suite is gated at `--min=100` (see the `test` script in `composer.json`), so any line left uncovered fails the build.
- **Always check coverage before pushing.** Run the full gate — which also runs Pint, PHPStan, and Rector — with `./vendor/bin/sail composer test` (this executes `lint:check`, `types:check`, `refactor:check`, and `php artisan test --coverage --min=100`). Do not push or open/update a PR until it reports `Total: 100.0 %` with a clean Rector dry-run.
- If new code drops coverage, add or update tests until it is back at 100%. When a line reads as uncovered even though a test exercises it (e.g. the `: null` branch of a multi-line ternary is a known PCOV line-attribution quirk), collapse it onto a single line rather than leaving the gate red.

## Reporting Bugs Found While Doing Something Else

- **When you discover a bug, broken tooling, or other pre-existing defect while implementing an unrelated feature, do not fix it inline.** Keep the current change focused on its own scope.
- **Before filing anything, check for an existing issue** covering the same defect: run `gh issue list --state open --search "<keywords>"` (and search closed issues too). If one already exists, reference it in your PR instead of opening a duplicate.
- If none exists, open a GitHub issue with `gh issue create` describing the problem: what's broken, how it surfaced (reference the PR/feature you were working on), why it matters, and clear acceptance criteria for the fix. Apply a fitting label (e.g. `tech-debt`).
- Mention the new issue in the PR of the feature you're working on so the discovery is traceable, then continue with the original task.
- Only fix the defect inline if it directly blocks the feature you're implementing; otherwise defer it to the issue.

## Self-Hosting Documentation (Starlight) — keep it in sync

- **The public docs site lives in `docs/`** (an Astro Starlight project, deployed to Cloudflare Pages). It is self-contained — its own `package.json`/`node_modules`, isolated from the Laravel/Vite app and **excluded from every app quality gate** (ESLint, Pint, Prettier, PHPStan, Rector, `vue-tsc`). Content is Markdown/MDX under `docs/src/content/docs/`; site config (title, sidebar, edit links) is in `docs/astro.config.mjs`. Not to be confused with `dev-docs/` (internal ADRs, PRD, design notes — not published).
- **When a change adds or alters anything operator-facing, update the docs in the same PR.** This is a non-negotiable part of "done" for such changes, the same way i18n catalogs and tests are. Triggers include:
  - A new or changed **`.env`/config setting** an operator would set — especially a feature toggle (like `REGISTRATION_ENABLED`, `EMAIL_VERIFICATION_ENABLED`). Update `docs/src/content/docs/reference/environment-variables.md` and, if it's an on/off switch, `docs/src/content/docs/reference/feature-toggles.md`.
  - A change to **install, configuration, reverse-proxy/TLS, first-run, or upgrade** steps, or to the **production stack** (services in `docker-compose.prod.yml`, volumes, drivers). Update the relevant page under `docs/src/content/docs/self-hosting/` and `docs/src/content/docs/reference/architecture.md`.
- **Source the docs from the code, not memory.** Read the actual `config/*.php`, `.env.example`, and `docker-compose.prod.yml` so defaults and behaviour are accurate; if the root `README.md` disagrees with the compose file, the compose file wins (and file a `documentation` issue for the stale README).
- **Verify the site still builds:** `cd docs && npm run build` (Node tooling for the docs site runs on the host, not through Sail — its `node_modules` is a separate host install). See `docs/README.md` for dev/build commands. Add a matching key to any relevant page's sidebar entry in `astro.config.mjs` when you add a new page.
