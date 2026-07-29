---
paths:
  - "tests/**"
  - "bin/browser-tests"
  - "composer.json"
  - "phpunit.xml"
  - ".github/workflows/tests.yml"
---

# Testing

## Code Coverage

- **100% code coverage is required — this is non-negotiable.** The test suite is gated at `--min=100` (see the `test` script in `composer.json`), so any line left uncovered fails the build.
- **Always check coverage before pushing.** Run the full gate — which also runs Pint, PHPStan, and Rector — with `./vendor/bin/sail composer test` (this executes `lint:check`, `types:check`, `refactor:check`, and `php artisan test --parallel --coverage --min=100`). Do not push or open/update a PR until it reports `Total: 100.0 %` with a clean Rector dry-run.
- If new code drops coverage, add or update tests until it is back at 100%. When a line reads as uncovered even though a test exercises it (e.g. the `: null` branch of a multi-line ternary is a known PCOV line-attribution quirk), collapse it onto a single line rather than leaving the gate red.

## Parallelism

- **The gate runs the suite in parallel, like CI does.** Paratest gives each worker its own `testing_N` database and merges the per-worker PCOV reports, so `--min=100` still bites while the run takes roughly a third of the serial wall clock. The browser suite (`composer test:browser`) runs in parallel too, through `bin/browser-tests`: binding Reverb and a live server turned out not to prevent sharding (pest-plugin-browser reconnects every worker to one shared Playwright server and gives each its own HTTP port), and it is ~4.4x faster that way. **Its worker count is capped at half the cores, never below two** — `--parallel` left to its own default runs as many workers as cores, which is both slower and much flakier (it reproduces the #786 geometry failures on demand). Pin another count with `BROWSER_TEST_PROCESSES=N` if you need to measure one. **`bin/browser-tests` also reaps what a run leaves behind** — the Playwright server every run leaked (pest ends the parallel parent with `exit()`, so the plugin never stops it) and the paratest workers a killed run strands — on the way in and on the way out, naming each one. Those leftovers eat the very cores the cap protects, which turned repeated sweeps into fabricated failures (#955). Only processes orphaned onto init *and* running out of this checkout are touched, so a run never reaps a sibling worktree's or the PHP gate's.
- **Every worker's schema bootstrap is guarded against Postgres deadlocks.** The first test a worker runs creates that worker's database and migrates it, and all of them do so at once; those transactions write shared catalogs (`pg_shdepend`), and because transaction-id locks are cluster-wide the wait cycle can span two worker databases and get one killed with `SQLSTATE[40P01]` (#812). `Tests\Support\SchemaBootstrapGuard` (wired into `Tests\TestCase::setUp()`) holds a shared `flock` for the normal path — so the bootstrap stays as parallel as it was — and re-runs a deadlocked worker under an exclusive one, alone in the cluster. Do not remove it to "simplify" the base test case; a red run would come back as a rerun-and-it-passes flake.

## Browser tests stay headless

- **The Pest browser suite is already headless by default** (`Playwright::$headless` in `pestphp/pest-plugin-browser`), so this is about not turning it off. Do not pass `--headed`, do not call `pest()->browser()->headed()` in `tests/Pest.php`, and do not leave a `->debug()` on a `visit()`.
- **`->debug()` is the one that bites**, because opening a window is only half of what it does: it also calls `Only::enable()`, so the run silently shrinks to the single test carrying it. A stray `->debug()` therefore reports green while skipping the whole suite. `--headed` is the loud one by comparison, and it is rejected outright under `--parallel`, which is how `bin/browser-tests` runs the suite.
- `tests/Unit/HeadlessBrowserTestingTest.php` fails the gate if `--headed`, `headed()` or `->debug()` reaches the repo.
