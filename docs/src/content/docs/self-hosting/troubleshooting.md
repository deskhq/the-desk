---
title: Troubleshooting
description: Fix the first-run gotchas a healthy stack can still hit — a "Reconnecting…" banner from a wrong APP_URL, push notifications that silently never arrive, and .env edits that need a container recreate to take effect.
---

A fresh deploy can report every container `healthy` and still look broken, because
some settings are read by the **browser** (not the server) and some are baked into
a **boot-time snapshot** (not the live file). This page is symptom-first: find the
banner or behaviour you are seeing, then apply the fix.

If none of these match, start with the container logs, which name most boot-time
failures directly:

```bash
docker compose logs app reverb --tail=100
```

The bare `docker compose` needs no `-f` because `.env` sets `COMPOSE_FILE`. See
[the COMPOSE_FILE variable](/self-hosting/installation/#the-compose_file-variable).

## "Reconnecting…" after login / WebSocket won't connect

**Symptom.** The app loads and you can sign in, but a **"Reconnecting…"** banner
sits at the top and real-time updates (new messages, typing indicators, presence)
never arrive. The page HTTP works; only the WebSocket is failing.

**Cause.** The browser reaches Reverb at a **different** address than the server
does, and that browser-facing address is derived from `APP_URL`. Its host defaults
to the host of `APP_URL` (`App\Support\ReverbConfig::forFrontend()` reads
`parse_url(APP_URL)`; see `config/broadcasting.php`), so if `APP_URL` was left at
the default or set to the wrong value, the browser opens the WebSocket against the
wrong origin while plain HTTP still works. A reverse proxy that does not forward
WebSocket **upgrade** requests produces the same banner.

**Fix.** Check these in order:

1. **`APP_URL` matches the URL you actually load.** It must be the exact public
   scheme and host you type in the browser, e.g. `https://chat.example.com`, not
   `http://localhost` or a stale value.
2. **The browser-facing Reverb overrides are right for your proxy.** Behind a
   TLS-terminating proxy the browser reaches Reverb on `wss` / `443` even though
   the container speaks plain `http` on `8080`, so set `REVERB_PORT_PUBLIC=443` and
   `REVERB_SCHEME_PUBLIC=https`. The browser-facing host follows `APP_URL`; set
   `REVERB_HOST_PUBLIC` only if you serve Reverb from a dedicated WebSocket
   subdomain. See
   [Configuration → Reverb](/self-hosting/configuration/#reverb-websockets--mind-the-browser-vs-server-split).
3. **The reverse proxy forwards WebSocket upgrades** to the `reverb` service. Open
   the browser dev tools **Network → WS** tab and confirm the connection opens
   instead of failing repeatedly, following the
   [reverse-proxy verification steps](/self-hosting/reverse-proxy/#verifying).

:::caution
After editing `APP_URL` or any `REVERB_*` value, you must **recreate** the
containers for the change to take effect, not just save the file. See
[Changed `.env` but nothing changed](#changed-env-but-nothing-changed) below.
:::

## Nobody receives push notifications

**Symptom.** Members have turned browser notifications on, but nothing ever
arrives. Every container reports `healthy`, the queue is empty, and the logs look
ordinary.

**Cause.** Web push is the only channel the app sends message alerts through, on
purpose: there is no notification centre and no email for message traffic. So a
delivery that fails produces exactly the silence a member who never subscribed a
device would see. Worse, the notification is consumed off the queue *before* it
fails, so the queue drains to zero and the worker stays up: every surface-level
signal reads green while nothing is delivered.

**Fix.** Ask the instance directly:

```bash
docker compose exec app php artisan push:doctor
```

It checks the whole delivery path — the VAPID keypair is set and well-formed, the
subject is a contact a push service accepts, the image loads the extensions the
push library needs, the notification channel actually constructs, how many devices
are subscribed, and whether push jobs have already died — and exits non-zero when
push cannot be delivered, so you can also run it from a monitor.

Each line names its own fix:

- **VAPID keypair `WARN`, "not set".** Push is switched off. Generate a pair; see
  [Environment variables → Web push notifications](/reference/environment-variables/#web-push-notifications).
- **VAPID keypair `FAIL`.** Only one half is set, or a key was truncated when it
  was pasted in. Set both, in full.
- **Math extension or PHP extensions `FAIL`.** The image is missing something the
  push library needs. It cannot be fixed from `.env` — upgrade to a release that
  ships it.
- **Subscriptions `WARN`, "no device has subscribed".** Delivery is fine; nobody
  has opted in yet. Members turn it on per device under **Settings →
  Notifications**, and a browser only offers it over HTTPS.
- **Past failures `WARN`.** Push jobs have already died. Read them with
  `docker compose exec app php artisan queue:failed`; the exception names the
  cause. Once it is fixed, clear the backlog with `php artisan queue:flush`.

Failed jobs are also written to the log from the moment they fail, so
`docker compose logs app queue --tail=100` shows the underlying exception without
waiting for anyone to inspect the queue.

## Changed `.env` but nothing changed

**Symptom.** You edit `.env` on the host, but the running instance keeps behaving
as before. The file is bind-mounted live into the containers
(`./.env:/app/.env:ro` in `docker-compose.prod.yml`), so it is reasonable to
expect the edit to apply on its own. It does not.

**Cause.** Two boot-time mechanisms pin configuration for a container's lifetime:

- The entrypoint runs `php artisan config:cache` at start (`docker/entrypoint.sh`),
  so every long-running process (`app`, `reverb`, `queue`, `scheduler`) serves a
  config **snapshot** baked when its container was created, not the current file.
- Compose injects the file through `env_file`, and a running process's environment
  is fixed once it starts.

Both are cleared only by **recreating** the container, which re-runs the entrypoint
and rebuilds the snapshot from the current `.env`.

**Fix.** Recreate the stack so every service re-reads the file:

```bash
docker compose up -d --force-recreate
```

A plain `docker compose up -d` usually recreates the affected containers on its own
(Compose detects the changed `env_file`); `--force-recreate` guarantees it. See
[Configuration → Applying changes](/self-hosting/configuration/#applying-changes).

If you would rather not restart everything, rebuild the cache in place and bounce
only the background services (their entrypoints re-cache from the live file on
restart):

```bash
docker compose exec app php artisan config:cache
docker compose restart reverb queue scheduler
```
