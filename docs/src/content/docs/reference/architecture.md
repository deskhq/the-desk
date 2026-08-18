---
title: Architecture
description: What runs in the production stack and why — the web app, Reverb, queue, scheduler, Postgres, Meilisearch, and Redis.
---

The production stack (`docker-compose.prod.yml`) is a set of containers, all
driven by the same `.env`. Its values are injected into every app-role container,
and the file itself is also bind-mounted read-only at `/app/.env`, so artisan
commands run against a container (maintenance, seeding the public demo) resolve
configuration exactly like a standard on-disk install. The app image is served
with [FrankenPHP](https://frankenphp.dev/). By default the five app-role services
(`app`, `reverb`, `queue`, `queue-broadcasts`, `scheduler`) share one **prebuilt
image** pulled from the registry. `unfurler` runs that same image and so needs no
second pull, but it is not an app-role container: it runs a Go binary rather than
the application, and holds none of the application's environment, storage or
branding (see [Link previews](#link-previews) below). The optional
`docker-compose.build.yml` overlay the optional `docker-compose.build.yml` replaces that pull with a local build from source (see
[Installation](/self-hosting/installation/#build-from-source)).

## Services

| Service       | Role                                                        |
| ------------- | ----------------------------------------------------------- |
| `app`         | FrankenPHP web server (serves HTTP; runs migrations on boot)|
| `reverb`      | WebSocket server for real-time broadcasting                 |
| `queue`       | Queue worker (`queue:work`) — sends mail, delivers jobs     |
| `queue-broadcasts` | Queue worker dedicated to real-time broadcasts        |
| `scheduler`   | Scheduled tasks (`schedule:work`) — due scheduled messages, invitation pruning |
| `pgsql`       | PostgreSQL database (named volume `pgsql-data`)             |
| `meilisearch` | Full-text search index (version-scoped named volume)       |
| `unfurler`    | Link-preview service (fetches and parses linked pages)       |
| `redis`       | Cache, session, and queue backend (named volume `redis-data`)|

Every app process (`app`, `reverb`, `queue`, `queue-broadcasts`, `scheduler`)
waits for `pgsql`, `redis`, and `meilisearch` to report healthy before it starts.

### Why broadcasts get their own worker

Every real-time update — a new message, an edit, a reaction, a typing indicator,
presence, read state — is a queued job. So are webhook deliveries, exports, and
mail, and each of those can spend seconds waiting on somebody else's server. On a
single worker one slow job holds every message behind it, and no amount of queue
prioritisation helps: priority picks the *next* job, it cannot interrupt the one
already running.

A link unfurl used to be the worst of them, spending up to five seconds on
outbound HTTP; it is now a single call to the `unfurler` service, which fetches
every link in a message at once. That removed the original reason for this split
but not the split itself, because webhook delivery and audit exports are still
sat on the shared queue and still block it.

So broadcasts are routed to their own `broadcasts` queue and drained by
`queue-broadcasts`, which runs nothing else. The shared `queue` worker still
lists `broadcasts` ahead of `default`, as a safety net: if you run a customised
compose file and never add the new service, broadcasts are still delivered — they
just queue behind slow jobs again, rather than stopping.

## Link previews

Posting a URL queues a background unfurl: something has to fetch that page and
read its Open Graph tags. That fetch is the only request the server makes on a
member's say-so — the address is whatever somebody typed into a message — so it
does not happen inside the application.

`unfurler` is a small Go service that does it instead. It ships inside the same
image (nothing extra to pull, scan or pin: one `APP_VERSION` still describes the
whole stack) but runs as its own container holding nothing:

- no `.env`, so it never sees `APP_KEY`, the database password, or any other
  secret
- no `storage-app`, no branding mount, and a read-only filesystem
- no published host port, so it is reachable only as `unfurler:8080` from inside
  the compose network, and `UNFURLER_TOKEN` authenticates every request so
  another container on that network cannot use it as an open fetcher

It checks each destination address immediately before connecting, on every
redirect hop, and reads at most 2 MB of any page. See
[Security](/reference/security/#link-previews-run-in-their-own-service).

**If you do not run it**, link previews simply do not resolve: cards settle as
failed and links render plainly. Messages still post, edit, broadcast and search
exactly as before. Leaving `UNFURLER_URL` unset turns unfurling off deliberately,
for an instance that wants no outbound fetching at all.

## Health checks

The app image itself declares no health check, so `docker compose ps` reports
each app-role service by what it actually serves:

| Service            | Health in `docker compose ps` | Probe                       |
| ------------------ | ----------------------------- | --------------------------- |
| `app`              | `healthy` / `unhealthy`       | `GET /up` (HTTP, port 8080) |
| `reverb`           | `healthy` / `unhealthy`       | `GET /up` (HTTP, port 8080) |
| `queue`            | `Up` (no health column)       | none (no HTTP surface)      |
| `queue-broadcasts` | `Up` (no health column)       | none (no HTTP surface)      |
| `scheduler`        | `Up` (no health column)       | none (no HTTP surface)      |
| `unfurler`         | `healthy` / `unhealthy`       | `GET /healthz` (HTTP, port 8080) |

The workers (`queue:work` / `schedule:work`) have nothing to curl, so they carry
no health check by design and show a plain `Up`. That confirms the container is
running, not that jobs are being processed; check the worker logs (`docker
compose logs queue`, `docker compose logs queue-broadcasts`, `docker compose logs
scheduler`) to confirm throughput. `app` and `reverb` both answer `GET /up`, so
a genuine outage flips them to `unhealthy`.

## Data flow

- **HTTP** requests hit `app` (FrankenPHP) behind your reverse proxy.
- **Real-time** updates are queued on `broadcasts`, picked up by
  `queue-broadcasts`, and pushed over WebSockets by `reverb`; the browser
  connects to it through your TLS proxy.
- **Background work** (email, link previews via `unfurler`, webhook delivery, scheduled message
  delivery) runs on `queue` and `scheduler`.
- **Search** queries go to `meilisearch`; the index is derived from Postgres and
  rebuilt with `php artisan search:sync` when needed.

## Integrations platform

External systems reach a workspace through the **integrations platform**, gated
by the [`INTEGRATIONS_ENABLED`](/reference/feature-toggles/#integrations-platform)
toggle (with it off, the API and webhook endpoints `404` and the management UI
hides):

- The versioned [**REST API**](/reference/api/) under `/api/v1` is served by
  `app`, authenticated by hashed bearer tokens and throttled per token.
- **Bots** are workspace users of a `bot` type, scoped to a team; they post
  through the API exactly like a person, gated by channel membership.
- [**Incoming webhooks**](/reference/incoming-webhooks/) accept a `POST` to an
  opaque secret URL and post it into one channel as a bot.
- [**Outgoing webhooks**](/reference/webhooks/) deliver subscribed events as
  signed `POST`s from the `queue` worker, with retries, per-attempt logging, and
  auto-disable on repeated failure.

All lifecycle actions (create, revoke, rotate, re-enable, auto-disable) are
recorded in the workspace audit log; secret values are never logged.

## Storage backends

Cache, session, and the queue all use the **Redis** driver
(`CACHE_STORE` / `SESSION_DRIVER=redis`, `QUEUE_CONNECTION=redis`, with
`REDIS_HOST=redis`). Broadcasting uses **Reverb**. Redis persists to a named
volume with `appendonly` enabled, so queued jobs survive a restart.

Workers wait on Redis for work rather than polling it on a timer
([`REDIS_QUEUE_BLOCK_FOR`](/reference/environment-variables/#queue-workers)), so
a job starts within milliseconds of being dispatched instead of after the next
poll.

The **Active sessions** panel (Security settings) — listing signed-in devices
and revoking them — is backed by an owned per-user session index kept in the
cache, so it works under the default Redis session driver with no need to switch
`SESSION_DRIVER` to `database`.

## Persistent volumes

| Volume                        | Contents                          |
| ----------------------------- | --------------------------------- |
| `pgsql-data`                  | PostgreSQL database                |
| `the-desk-meili-<version>`    | Meilisearch index (version-scoped) |
| `redis-data`                  | Cache, session, queued jobs        |
| `storage-app`                 | Uploaded files                     |

`storage-app` is mounted by all five app-role services (`app`, `queue`,
`queue-broadcasts`, `reverb`, `scheduler`) and deliberately **not** by
`unfurler`, which holds no application state at all. On a fresh volume Docker seeds it
from the image, so the workers wait for `app` to start (`depends_on: app`) and
exactly one container performs that seeding — starting them together makes the
daemon race itself and one container fails with `mkdir …/_data/private: file
exists`.

These survive `docker compose down` / `up`. See
[Upgrading](/self-hosting/upgrading/) for how the version-scoped Meilisearch
volume behaves across upgrades.

## Host bind mounts

| Path on the host | Mounted at              | Contents                          |
| ---------------- | ----------------------- | --------------------------------- |
| `./.env`         | `/app/.env` (read-only) | Your configuration                 |
| `./branding`     | `/app/storage/branding` (read-only) | Replacement brand assets |

`./branding` is where whitelabeling happens: the app resolves the logo mark,
favicons, Open Graph image and PWA icons from it per request, falling back file
by file to the ones shipped in the image. It is a bind mount rather than a named
volume so the files are yours to edit with an ordinary editor, and because it
lives outside the image it survives every upgrade. Compose creates it empty on
first `up`, and an empty directory changes nothing. See
[Branding](/self-hosting/branding/).
