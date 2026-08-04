# Is FrankenPHP worker mode safe for this application?

Research for [#1235](https://github.com/deskhq/the-desk/issues/1235), a ticket on the
[navigation feels instant](https://github.com/deskhq/the-desk/issues/1232) map.

**Evidence only. This document does not decide.** The decision is
[do we run FrankenPHP in worker mode?](https://github.com/deskhq/the-desk/issues/1238),
which this ticket blocks. Nothing below argues for or against adoption; where a
finding has an obvious fix, the fix is named so the decision can be costed, not
so it can be assumed.

Audited at `b76d47d4` (develop, 2026-08-04) against `laravel/framework 13.17`,
`inertiajs/inertia-laravel 3.x`, PHP 8.5, `dunglas/frankenphp:1-php8.5-alpine`.

---

## Part 1 — External: how worker mode actually behaves

### There is no "worker mode" you can just switch on

FrankenPHP worker mode is not a Caddyfile flag over an unchanged app. It replaces
`public/index.php` as the entry point with a **worker script** that boots once and
then loops on `frankenphp_handle_request($handler)`. Somebody has to write that
loop and decide what it resets per iteration.

For Laravel there are exactly two ways to get one:

1. **`laravel/octane`** — the FrankenPHP driver ships the worker script and, more
   importantly, ~40 reset listeners. This is what both the
   [FrankenPHP Laravel docs](https://frankenphp.dev/docs/laravel/) and the
   [Laravel Octane docs](https://laravel.com/docs/13.x/octane) point you at;
   FrankenPHP's own documentation presents worker mode for Laravel *exclusively*
   through Octane.
2. **A hand-rolled `worker.php`** — no new Composer dependency, and you re-implement
   the reset listeners yourself. Part 2 is largely a catalogue of what that costs.

The distinction matters for this map because the Dockerfile's own header claims
"no extra Composer dependency (unlike Laravel Octane)" as a property of the current
setup, and CLAUDE.md forbids adding a dependency without approval. So route 1 is
gated on a decision, and route 2 is gated on the list below.

### What FrankenPHP resets, and what it does not

From [the worker docs](https://frankenphp.dev/docs/worker/):

- **Reset automatically:** `$_GET`, `$_POST`, `$_COOKIE`, `$_FILES`, `$_SERVER`,
  `$_REQUEST`.
- **Not reset:** `$_ENV`, static class properties, function-level `static`
  variables, globals in the worker script's scope, and anything held in memory by
  the application.
- **Restart valves:** `MAX_REQUESTS` (env) restarts a worker after N requests;
  `max_consecutive_failures` in the Caddyfile handles a crashing worker;
  `--watch` is a dev-only reload.

That list is the whole of FrankenPHP's contribution to isolation. Everything
application- or framework-shaped is somebody else's problem. FrankenPHP v1.11.2
(Feb 2026) shipped a fix for a **session leak between requests in worker mode**,
classed as a security patch — a reminder that the runtime itself has had this bug
class, not only the apps on top of it.

### What Octane resets for you (v2.18.0, verbatim)

This is the concrete answer to "what does Laravel reset for you". `composer require
laravel/octane --dry-run` against this repo's lock resolves to **v2.18.0** (see
Part 3), whose `Octane::prepareApplicationForNextRequest()` /
`prepareApplicationForNextOperation()` are:

**Per request:**

```
FlushLocaleState, FlushQueuedCookies, FlushSessionState, FlushAuthenticationState,
EnforceRequestScheme, EnsureRequestServerPortMatchesScheme,
GiveNewRequestInstanceToApplication, GiveNewRequestInstanceToPaginator
```

**Per operation (request, queued task, or tick):**

```
CreateConfigurationSandbox, CreateUrlGeneratorSandbox,
GiveNewApplicationInstanceTo{AuthorizationGate, BroadcastManager, DatabaseManager,
  DatabaseSessionHandler, FilesystemManager, HttpKernel, LogManager, MailManager,
  NotificationChannelManager, PipelineHub, CacheManager, SessionManager,
  QueueManager, Router, ValidationFactory, ViewFactory},
FlushDatabaseRecordModificationState, FlushDatabaseQueryLog,
RefreshQueryDurationHandling, FlushArrayCache, FlushLogContext, FlushMonologState,
FlushStrCache, FlushTranslatorCache, FlushVite,
PrepareInertiaForNextOperation, PrepareLivewireForNextOperation,
PrepareScoutForNextOperation, PrepareSocialiteForNextOperation
```

**On operation terminated:** `FlushOnce`, `FlushTemporaryContainerInstances`
(which calls `resetScope()` + `forgetScopedInstances()`, then forgets whatever is
listed in `octane.flush`).

Two of those are load-bearing for this app specifically and are verified below:
`PrepareInertiaForNextOperation` calls `ResponseFactory::flushShared()`, and
`CreateUrlGeneratorSandbox` restores `URL::defaults()`.

The docs state the boundary plainly:

> Octane will automatically handle resetting any first-party framework state
> between requests. However, Octane does not always know how to reset the global
> state created by your application.

So: **`scoped()` bindings are flushed; `singleton()` bindings are not.** That single
sentence separates finding 1 below from findings 2-5.

### Versus Swoole and RoadRunner

- **Same isolation model, different process model.** All three keep one booted app
  per worker process and feed it requests; none of them fork per request. The
  leak surface is identical, which is why Octane's listener list is server-agnostic.
- **Swoole/OpenSwoole** additionally offer coroutines, `Octane::concurrently()`,
  ticks and the Swoole-table cache — none of which apply here, and all of which
  require a PECL extension this image does not build.
- **RoadRunner** is a separate Go binary to ship and supervise. FrankenPHP is
  already the server in this image, so it is the only one of the three that adds
  no new binary.
- **FrankenPHP-specific:** the worker is Caddy-hosted, so early hints, HTTP/3 and
  Brotli/Zstd come along; `octane:frankenphp` spawns `frankenphp run` as a *child*
  of the PHP CLI process (Part 3).

### Interaction with the rest of this stack

Not a factor. `docker-compose.prod.yml` runs `reverb`, `queue`, `queue-broadcasts`
and `scheduler` as separate services off the same image with their own commands;
only the `app` service serves HTTP. Worker mode changes the `app` command and
nothing else. Reverb is already a long-lived process and is unaffected. Queue
workers already run a booted-once app and already flush scoped instances between
jobs — which is precisely why the packages in finding 5 use `scoped()`.

Two second-order notes:

- **Octane's per-operation listeners also fire for queued tasks and ticks**, but
  those are Swoole-only features. Nothing changes for the `queue` service.
- **`DisconnectFromDatabases` is commented out** in Octane's default config, so
  Postgres and Redis connections persist for the worker's life. That is the intent
  (it is part of the win) but it makes an idle-timeout disconnect a live failure
  mode that classic mode cannot have.

---

## Part 2 — In-repo: what would survive a request

The headline is that **this codebase is unusually clean for this audit**. The
sweeps that usually produce the long list produce nothing:

- **Exactly one non-constant static property in `app/`**: `DirectoryUser::$objectClasses`
  (`app/Ldap/DirectoryUser.php:28`), a fixed LDAP object-class list required by
  LdapRecord. Not request state.
- **No function-level `static $x` caches** anywhere in `app/`.
- **No `addGlobalScope`, no `::macro()`, no `Route::bind`, no `Relation::morphMap`.**
- **No `config()` or `Config::set()` write on a request path.** The one runtime
  config write is `DemoServiceProvider.php:40` (`mail.default => array`) and it is
  boot-time and idempotent.
- **No container, request or config repository injected into a singleton's
  constructor** — the three cases the Octane docs call out. The four app-owned
  singletons take a `Cache`, a config string, or nothing.
- **No `URL::forceRootUrl`, no boot-time `Auth::user()`.** `FortifyServiceProvider`
  touches `Auth::guard(...)` only inside a per-request `authenticateUsing` callback
  (`app/Providers/FortifyServiceProvider.php:143`).
- **`once()`** is used once (`app/Rules/TeamName.php:34`). Laravel's `Once` keys a
  `WeakMap` by object, so entries die with the object and there is no
  `spl_object_hash` reuse hazard. Octane flushes it anyway (`FlushOnce`). Safe
  under both routes; noted so nobody "fixes" it.

What follows is the list of sites that are *not* clean.

### 1. `PresenceRegistry` is a `singleton()` whose comment says "per request" — leaks with or without Octane

`app/Providers/AppServiceProvider.php:40-42`

```php
// One instance per request, so the presence aggregate a page needs for
// dozens of rendered users costs one cache read per distinct user.
$this->app->singleton(PresenceRegistry::class);
```

`app/Support/PresenceRegistry.php:31-41` holds the state the comment describes:

```php
/**
 * Aggregates already resolved this request, keyed by user id.
 * ...
 * @var array<string, PresenceState>
 */
private array $resolved = [];
```

The documented intent is request scope; the binding expresses process scope. In
classic mode those are the same thing, so the mismatch has never mattered. In a
worker they diverge, with two consequences:

- **Correctness.** `$resolved` is never cleared, so every user's presence freezes
  at whatever it was the first time the worker resolved it. Someone who goes idle
  reads as active for the life of the process, and vice versa. This is a
  cross-request *staleness* bug, not a cross-tenant disclosure — presence is
  visible to every workspace member anyway.
- **Memory.** The map grows by one entry per distinct user seen, forever.

**This is the only finding in this document that survives adoption of Octane**,
because Octane does not reset application singletons. Fix is one word:
`singleton(` → `scoped(`, which is exactly what the comment already says and what
the packages in finding 5 do. `scoped()` behaves identically in classic mode.

### 2. `Inertia`'s shared props accumulate on a singleton — and this app shares *conditional* keys

`vendor/inertiajs/inertia-laravel/src/ServiceProvider.php:32` binds
`Inertia\ResponseFactory` as a singleton. `ResponseFactory::share()` (`:102-114`)
does `array_merge` into `protected $sharedProps` (`:47`), and **nothing in the
package ever calls `flushShared()`** (`:141`) — the only callers are outside it.
`Inertia\Middleware::handle()` calls `Inertia::share($this->share($request))` and
merges; it never clears.

For most apps this is invisible, because the middleware shares the same key set
every request and each value is simply overwritten. **This app is not most apps.**
`app/Http/Middleware/HandleInertiaRequests.php:268-272` spreads two prop sets that
exist only conditionally:

```php
...$shell?->threadsPanelProps($pinned, ThreadInboxFilter::fromQuery($request->query('filter'))) ?? [],
...$shell?->searchPanelProps($pinned, MessageSearchPanel::criteriaFromRequest($request)) ?? [],
```

Both return `[]` unless the matching dock destination is pinned
(`app/Support/WorkspaceShell.php:212` and `:249`), and both return `[]` for a
guest or an off-workspace route (`$shell` is null). So:

| key | source |
| --- | --- |
| `threads`, `unreadThreadCount` | `WorkspaceShell::threadsPanelProps()` — only on `?nav=threads` |
| `searchCriteria`, `searchResults`, `searchWorkspaceChannels` | `WorkspaceShell::searchPanelProps()` — only on `?nav=search` |

Without a reset, user A's `?nav=search` visit leaves `searchResults` on the
singleton, and user B's next visit — which shares an array *without* that key —
ships it. Worse than a stale array: `searchResults` is
`$panel->results(...)`, a first-class callable bound to a `MessageSearchPanel`
holding **A's viewer, A's team and A's criteria** (`app/Support/WorkspaceShell.php:253-258`).
Inertia would *resolve* it on B's request, running A's search against A's
workspace and serialising the results into B's payload. Same shape for
`threads`, which closes over a `ThreadInbox` holding A's viewer.

That is a cross-tenant message-content disclosure, and it is the single most
serious thing in this audit.

**Under Octane this is handled**: `PrepareInertiaForNextOperation` calls
`flushShared()` when the method exists — and it does, at `ResponseFactory.php:141`.
Worth stating explicitly because Octane v2.18.0 only dev-requires
`inertiajs/inertia-laravel ^1.3.2|^2.0` and this app is on **v3**; the listener is
guarded by `method_exists`, so it works, but it is working outside its tested
range. The rest of v3's `ResponseFactory` state (`$rootView`, `$version`,
`$clearHistory`, `$encryptHistory`, `$urlResolver`, `$componentTransformer`) is
*not* cleared by `flushShared()`; of those, `$rootView` and `$version` are set on
every request by the middleware, `$urlResolver` is never set (this app does not
override `urlResolver()`), and `clearHistory`/`encryptHistory` are never called
anywhere in `app/`, `config/` or `resources/js/`. So the residue is empty today,
but it is a seam that a future `Inertia::clearHistory()` on logout would open —
that flag would then stick on for every subsequent response the worker serves.

**Hand-rolled, this is a leak you must close yourself.**

### 3. `SetTeamUrlDefaults` writes to the URL generator and only overwrites conditionally

`app/Http/Middleware/SetTeamUrlDefaults.php:19-24`

```php
if ($currentTeam = $request->user()?->currentTeam) {
    URL::defaults([
        'current_team' => $currentTeam->slug,
        'team' => $currentTeam->slug,
    ]);
}
```

`URL::defaults()` mutates the `UrlGenerator` singleton and is never cleared. The
`if` is the problem: a guest request, a signed-in user with no current team, an
`api/*` request and an error page rendered outside the web group all skip the
write, so `route()` on that request resolves team-scoped parameters to **the
previous request's tenant**. A signed-out 404 page or a redirect built for a guest
would carry a stranger's workspace slug in its URLs.

**Under Octane this is handled** by `CreateUrlGeneratorSandbox`. Hand-rolled, it is
yours to reset.

### 4. `SetLocale` is web-group only, so non-web requests inherit the last locale

`app/Http/Middleware/SetLocale.php:21` calls `App::setLocale(...)`, and
`bootstrap/app.php:78-91` registers `SetLocale` in the **web group only**. The
`api` group, the SCIM surface and `webhooks/incoming/*` never run it.

In a worker, the translator keeps whichever locale the last web request set, so an
English API consumer can receive French validation messages after a French user's
page load. Lower severity than 2 and 3 — it is copy, not data — but it is a real
cross-request bleed and it is invisible in every test.

**Under Octane this is handled** by `FlushLocaleState`.

### 5. The CSP nonce depends on `scoped()` bindings actually being flushed

`app/Http/Middleware/AddContentSecurityPolicyHeaders.php:39`

```php
// One nonce per request, shared by the Blade shell's inline appearance
// script (@cspNonce) and every @vite tag.
Vite::useCspNonce(app('csp-nonce'));
```

`csp-nonce` and `NonceGenerator` are `scoped()` bindings
(`vendor/spatie/laravel-csp/src/CspServiceProvider.php:21-23`). A worker that does
not call `forgetScopedInstances()` per request emits **the same nonce on every
response for the life of the process**, which defeats the point of a nonce-based
CSP: an injected `<script nonce="...">` becomes replayable across users once the
value is observed. The `Vite` singleton side is separately covered by Octane's
`FlushVite`.

The same mechanism covers `spatie/laravel-activitylog`'s `CauserResolver`,
`ActivityLogStatus` and `ActivityBuffer`
(`vendor/spatie/laravel-activitylog/src/ActivitylogServiceProvider.php:31-35`) —
all `scoped()`. A worker that does not flush them would attribute audit-log
entries to **the previous request's causer**, which for a compliance surface
([#417](https://github.com/deskhq/the-desk/issues/417)) is worse than the CSP
issue. Fortify's `RedirectsIfTwoFactorAuthenticatable` is `scoped()` too
(`vendor/laravel/fortify/src/FortifyServiceProvider.php:78`).

**Under Octane all of these are handled** by `FlushTemporaryContainerInstances`.
Hand-rolled, forgetting one line of the loop silently mis-attributes the audit log.

### Audited and clean

Named here so the decision ticket knows the list is bounded, and so a later session
does not re-audit them:

| Site | Verdict |
| --- | --- |
| `WorkspaceShell` (`app/Support/WorkspaceShell.php`) | `final readonly`, constructed per request from `forRequest()`, never bound in the container. No state to leak. |
| `TranslationCatalog` (`app/Support/TranslationCatalog.php`) | No properties; `messages()` reads and returns. Not memoised — reads the catalog JSON on every call, which is a worker-mode *opportunity*, not a hazard. |
| `ReverbConfig`, `WebPushConfig`, `FrequentEmoji` | Pure static methods over `config()` and queries. No instance or static state. |
| `SlashCommandRegistry` (singleton, `SlashCommandServiceProvider.php:39`) | Holds `$commands`, but it is populated at boot from a `const` list and keyed by name (`SlashCommandRegistry.php:22-25`), so registration is idempotent and contains no request or user state. |
| `Meilisearch\Client` (singleton, `AppServiceProvider.php:31`) | Stateless HTTP client. Keeping it is a win. |
| `IpGeolocator` (`AppServiceProvider.php:36`) | Bound with `bind()`, not `singleton()` — new per resolve. Its memoised reader dies with the instance. |
| `SessionRegistry`, `AuditRecorder`, `SecurityEventRecorder`, `LogActors` | Not container-bound as singletons; no cross-request state. |
| `spatie/laravel-data`'s `DataConfig` (singleton) | Caches class metadata only. Another win. |
| `app/Ldap/DirectoryUser::$objectClasses` | Constant-in-practice config array. |
| `once()` in `app/Rules/TeamName.php:34` | `WeakMap`-backed; safe, and flushed by Octane regardless. |

---

## Part 3 — What adoption would mechanically involve, in *this* image

Facts, not a plan. Gathered because "worker mode costs operators nothing" is an
assumption the decision ticket inherits from the map, and it needs checking.

**Dependency resolution.** `composer require laravel/octane --dry-run
--ignore-platform-reqs` against the current lock succeeds and adds exactly three
packages: `laravel/octane v2.18.0`, `laminas/laminas-diactoros 3.8.0`,
`symfony/psr-http-message-bridge v8.1.0`. No conflicts, no advisories, nothing
else moves. (`composer.json`/`composer.lock` verified unchanged afterwards.)

**Process model changes.** `octane:frankenphp` does not *become* the server — it
spawns `frankenphp run -c <stub Caddyfile>` as a child `Process` from the PHP CLI
parent (`StartFrankenPhpCommand.php:88-92`). The container's PID 1 becomes a PHP
supervisor with a Go child, where today `CMD ["frankenphp", "run", ...]` is a
single process. Signal forwarding and `docker stop` semantics are worth verifying
rather than assuming.

**Runtime writes into the web root.** `ensureFrankenPhpWorkerIsInstalled()` copies
the package's worker stub to `public/frankenphp-worker.php` on every start
(`InstallsFrankenPhpDependencies.php:32-36`). `public` is chown'd to `www` in the
Dockerfile so the write succeeds, but a PHP file appears in the served root at
runtime. Baking it at build time avoids that.

**Interactive prompts on a failure path.** If the `frankenphp` binary is not on
PATH, or is older than Octane requires, Octane calls `confirm(...)` and offers to
**download** it (`InstallsFrankenPhpDependencies.php:44-51`, `:174-184`). In a
container with no TTY and possibly no egress, that is a confusing hang or crash
rather than a clear error. The `dunglas/frankenphp` base does provide the binary,
so the happy path is fine — but the failure mode is poor.

**Caddy's writable directories.** The Laravel docs' own Sail snippet sets
`XDG_CONFIG_HOME` and `XDG_DATA_HOME`, because Caddy needs somewhere to write
config and data. The runtime user is created with `adduser -u 1000 -S -G www www`
and **no home directory**, so `$HOME/.config/caddy` does not exist and is not
writable. Expect to set both variables explicitly.

**The `HEALTHCHECK NONE` decision and the admin port.** Octane defaults to a Caddy
admin endpoint on 2019, which is what the base image's dropped healthcheck probed.
Whether to re-enable it, and on which port, becomes live again.

**What already helps.** `opcache.validate_timestamps = 0` and the
classmap-authoritative autoloader (`docker/php/production.ini`, Dockerfile stage 1)
mean the code is immutable in the image, which is exactly the precondition worker
mode wants. `config:cache`/`route:cache`/`event:cache` already run in the
entrypoint, so `$_ENV` not being reset is a non-issue.

**Memory.** `memory_limit = 256M` is currently a per-request budget that resets on
every request. In a worker it becomes a per-process budget that accumulates.
`--max-requests` (Octane defaults to 500) or FrankenPHP's `MAX_REQUESTS` is the
valve.

---

## What the decision ticket inherits

Stated as inputs, not conclusions.

1. **Adoption route determines the size of the problem.** With Octane, the leak
   list collapses to **one site** (finding 1, `PresenceRegistry`) with a
   one-word fix — but it costs a Composer dependency that the Dockerfile's own
   header currently advertises the absence of, and CLAUDE.md gates. Hand-rolled,
   the list is **five sites** plus everything in Octane's 40-listener reset that
   this audit did not have to look for because a package already covers it.
2. **The dangerous finding is finding 2**, and it is dangerous for a reason the
   map created: `?nav=` dock props are conditionally shared. Any future
   conditionally-shared prop joins it, which is a standing hazard rather than a
   one-time fix.
3. **The coverage gate cannot see any of this.** Every test boots a fresh app, so
   all five findings are green today and would stay green after a regression.
   [#1238](https://github.com/deskhq/the-desk/issues/1238) asks what would catch
   one; this audit found nothing in the repo that could, and no cheap candidate
   beyond an architecture test asserting that no app-owned binding uses
   `singleton()` where its class holds mutable per-request state — which catches
   finding 1's shape and nothing else.
4. **The prize is bounded and already measured.** The baseline
   ([#1233](https://github.com/deskhq/the-desk/issues/1233)) put framework boot at
   **~100 ms of a ~370 ms navigation** — one of two server levers of equal size,
   the other being `share()`'s closures, which
   [#1234](https://github.com/deskhq/the-desk/issues/1234) already has a contract
   for and which needs no worker.
