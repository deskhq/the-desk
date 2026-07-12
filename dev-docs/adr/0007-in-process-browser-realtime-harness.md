# ADR-0007: Browser E2E realtime tests run against the app served in-process

- Status: Accepted
- Date: 2026-07-12
- Relates to: issue #53 (Browser / E2E test suite for realtime flows)

## Context

Headless feature tests exercise the broadcast *dispatch* but never the browser
half of a realtime flow: an Echo/Reverb subscription, the client whisper for
typing, and a second client reconciling a live edit or delete. Issue #53 asks for
end-to-end coverage of two clients exchanging messages, typing indicators, and
edit/delete echoes over real WebSockets.

`pestphp/pest-plugin-browser` (Playwright) serves the app **in-process** — the
same PHP process, database, and config as the test — via
`Pest\Browser\Drivers\LaravelHttpServer`. That co-location is what makes a live
Reverb server reachable from both the PHP publisher and the browser client with a
single host, but it also breaks assumptions that only hold for a fresh process
per request:

1. The app boots with `BROADCAST_CONNECTION=null` (phpunit.xml), so
   `routes/channels.php` registers channel authorization on the null broadcaster.
2. The singleton session `Store` replays attributes across requests, so a second
   browser context inherits the first request's login.
3. Every factory user gets a personal team as their `current_team_id`.
4. A full-page `navigate()` drops the in-process browser session.

## Decision

The realtime E2E suite lives in `tests/Browser` as a dedicated Pest `browser`
group that flips broadcasting to a real Reverb server per test, with **zero
production changes** — every workaround is test-only:

- `useReverbForBrowserTests()` sets `broadcasting.default=reverb`, derives the
  browser-facing `public_*` host/port/scheme from the same server-facing
  `REVERB_*` options, and **re-requires `routes/channels.php`** so channel auth
  attaches to the broadcaster the browser actually uses.
- A test-only global middleware, `ForgetGuardsPerRequest`, runs
  `session()->flush(); Auth::forgetGuards();` per request. It is prepended on the
  **contract** kernel (`app(Illuminate\Contracts\Http\Kernel::class)`), the
  singleton the in-process server resolves — so two browser contexts act as two
  users despite the shared process.
- Helpers seed a shared team + `#general` both members join, and point the second
  member's `current_team_id` at it, so both clients land in the same room after
  login with no `navigate()`.
- Auth goes through the real login UI (`visit('/login')`), never `actingAs`,
  since each `visit()` is an isolated browser context.

The group is excluded from phpunit's default `<testsuites>`, so `composer test`
never runs it and it stays out of the 100% coverage gate. It runs locally via
`composer test:browser` and in a dedicated `browser` CI job (Reverb started in the
background, bound to `127.0.0.1:8080`).

## Consequences

- The realtime browser contract — send/receive, typing whisper, edit echo, delete
  tombstone — is verified end-to-end against real Reverb, not mocked.
- The four in-process traps are solved once in `tests/Browser/Helpers.php` and
  `tests/Browser/Support/`, with no impact on production code or the coverage gate.
- CI cost is isolated in a separate job; the coverage job is untouched.
- New realtime E2E scenarios reuse the same helpers rather than re-deriving the
  traps (see also [ADR-0006](0006-realtime-lives-in-composables.md)).
