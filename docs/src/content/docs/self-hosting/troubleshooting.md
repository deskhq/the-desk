---
title: Troubleshooting
description: Fix the two first-run gotchas a healthy stack can still hit — a "Reconnecting…" banner from a wrong APP_URL, and .env edits that need a container recreate to take effect.
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
