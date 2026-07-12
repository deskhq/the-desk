---
title: Architecture
description: What runs in the production stack and why — the web app, Reverb, queue, scheduler, Postgres, Meilisearch, and Redis.
---

The production stack (`docker-compose.prod.yml`) is a set of containers, all
driven by the same `.env`. The app image is served with
[FrankenPHP](https://frankenphp.dev/).

## Services

| Service       | Role                                                        |
| ------------- | ----------------------------------------------------------- |
| `app`         | FrankenPHP web server (serves HTTP; runs migrations on boot)|
| `reverb`      | WebSocket server for real-time broadcasting                 |
| `queue`       | Queue worker (`queue:work`) — sends mail, delivers jobs     |
| `scheduler`   | Scheduled tasks (`schedule:work`) — due scheduled messages, invitation pruning |
| `pgsql`       | PostgreSQL database (named volume `pgsql-data`)             |
| `meilisearch` | Full-text search index (version-scoped named volume)       |
| `redis`       | Cache, session, and queue backend (named volume `redis-data`)|

Every app process (`app`, `reverb`, `queue`, `scheduler`) waits for `pgsql`,
`redis`, and `meilisearch` to report healthy before it starts.

## Data flow

- **HTTP** requests hit `app` (FrankenPHP) behind your reverse proxy.
- **Real-time** updates are broadcast over WebSockets by `reverb`; the browser
  connects to it through your TLS proxy.
- **Background work** (email, scheduled message delivery) runs on `queue` and
  `scheduler`.
- **Search** queries go to `meilisearch`; the index is derived from Postgres and
  rebuilt with `php artisan search:sync` when needed.

## Storage backends

Cache, session, and the queue all use the **Redis** driver
(`CACHE_STORE` / `SESSION_DRIVER=redis`, `QUEUE_CONNECTION=redis`, with
`REDIS_HOST=redis`). Broadcasting uses **Reverb**. Redis persists to a named
volume with `appendonly` enabled, so queued jobs survive a restart.

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

These survive `docker compose down` / `up`. See
[Upgrading](/docs/self-hosting/upgrading/) for how the version-scoped Meilisearch
volume behaves across upgrades.
