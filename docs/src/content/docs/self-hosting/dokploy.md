---
title: Deploying on Dokploy
description: Deploy The Desk on Dokploy with the shipped docker-compose.dokploy.yml. Compose settings, the environment tab, domains for the app and Reverb, and the four failures a PaaS deploy hits.
---

[Dokploy](https://dokploy.com) is a self-hosted PaaS that runs your Docker
Compose stacks behind its own Traefik. The repository ships
`docker-compose.dokploy.yml` for it, so a deploy is four screens of settings and
no YAML editing.

That file is [`docker-compose.prod.yml`](/self-hosting/installation/) with two
differences:

1. **No `ports:`.** Traefik reaches the containers over Dokploy's own
   `dokploy-network`, so publishing on the host does nothing. It also removes a
   collision: Reverb's default host port is `8080`, which is Dokploy's Traefik
   dashboard.
2. **`app` and `reverb` join `dokploy-network`** explicitly, next to the stack's
   own `default` network.

:::note
The same shape applies to Coolify, CapRover, and anything else that fronts
compose services with an injected proxy network. The file works there too; only
the names of the screens differ.
:::

## Before you start

- **An amd64 host.** The published image is built for `linux/amd64` only
  ([#205](https://github.com/deskhq/the-desk/issues/205)), so it will not boot on
  an ARM server.
- **A DNS A record** for your host (this page uses `chat.example.com`) pointing
  at the Dokploy server.
- **SMTP credentials**, as with any install. See
  [Requirements](/self-hosting/requirements/).

## Create the Compose application

In your Dokploy project, create a **Compose** service (not an Application) and
set:

| Field            | Value                            |
| ---------------- | -------------------------------- |
| Provider         | GitHub → `deskhq/the-desk`       |
| Branch           | `master`                         |
| Compose Path     | `./docker-compose.dokploy.yml`   |
| Compose Type     | Docker Compose                   |

:::caution[Use `master`, not `develop`]
`develop` is the release-candidate line: it is where `vX.Y.Z-rc.N` candidates are
cut from. `master` carries the stable releases. Deploying from `develop` is not
what you want unless you are deliberately testing a candidate.
:::

The branch only decides which **compose file** Dokploy reads. The application
image itself comes from the GitHub Container Registry and is selected by
`APP_VERSION` in the environment, so the two move independently. See
[Pinning and upgrading](#pinning-and-upgrading) below.

## The Environment tab

Dokploy writes the contents of this tab to a `.env` file next to the compose
file. That one file satisfies both halves of how the stack reads configuration:
compose's `env_file:` injects the values into every container, and the
`./.env:/app/.env:ro` bind mount puts the physical file inside the app so
`php artisan` resolves exactly the same configuration.

Start from
[`.env.prod.example`](https://github.com/deskhq/the-desk/blob/master/.env.prod.example)
and paste it in, then fill in your values. Three rules are specific to this tab:

1. **No quotes.** The editor normalises them away, so `APP_NAME="The Desk"` is
   written as `APP_NAME=The Desk` and phpdotenv aborts the boot with
   `Encountered unexpected whitespace`. Keep every value free of spaces. The
   shipped template already is.
2. **No `${OTHER_VALUE}` references.** Values reach the containers through
   `env_file:`, which passes them through literally, so a reference arrives as
   the characters you typed. Write the value out.
3. **No `COMPOSE_FILE` line.** It names `docker-compose.prod.yml`, which is the
   wrong file here. Dokploy passes its own Compose Path explicitly.

The values you must set by hand are the same everywhere: `APP_URL`, the
[required secrets](/self-hosting/installation/#required-secrets) (`APP_KEY`,
`DB_PASSWORD`, `MEILISEARCH_KEY`, and the `REVERB_APP_*` trio), your `MAIL_*`
credentials, and the Reverb values from the next section. `docker/gen-secrets.sh`
is the bare-VPS path and has nothing to write into here, so generate the secrets
locally and paste them:

```bash
docker run --rm ghcr.io/deskhq/the-desk:latest php artisan key:generate --show
docker run --rm ghcr.io/deskhq/the-desk:latest php artisan reverb:secret
openssl rand -hex 32   # DB_PASSWORD, and again for MEILISEARCH_KEY
```

## The Domains tab

Add **two** domain rows, one per service.

| Service  | Host               | Path   | Strip Path | Container Port | HTTPS |
| -------- | ------------------ | ------ | ---------- | -------------- | ----- |
| `app`    | `chat.example.com` | `/`    | off        | `8080`         | on    |
| `reverb` | `chat.example.com` | `/app` | **off**    | `8080`         | on    |

:::caution[Container Port is `8080`, not `8000`]
This field is the port **inside** the container, which is `8080` for both
services. `APP_PORT` (default `8000`) is the host-side publish port that
[Reverse proxy & TLS](/self-hosting/reverse-proxy/) talks about, and the Dokploy
template publishes no host ports at all. The field's placeholder is `3000`, which
is right for nothing here.
:::

Turn HTTPS on and let Dokploy issue a Let's Encrypt certificate. The app trusts
Traefik's `X-Forwarded-*` headers out of the box, so it generates `https://`
links with nothing further to configure.

### Reverb on the same domain (recommended)

The second row above routes `/app` to `reverb`, which is the path Echo opens its
WebSocket against (`wss://chat.example.com/app/{key}`). **Strip Path must be
off**: Reverb needs the prefix. This costs one DNS record and one certificate,
and the app has no route under `/app` to collide with.

Then, in the Environment tab:

```dotenv
REVERB_PORT_PUBLIC=443
REVERB_SCHEME_PUBLIC=https
REVERB_ALLOWED_ORIGINS=chat.example.com
```

Leave `REVERB_HOST_PUBLIC` unset. The browser-facing host then defaults to the
host of `APP_URL`, which is exactly what you want when both share a domain.

### Reverb on its own subdomain

The alternative is a `ws-` style subdomain, which needs its own public DNS record
and its own certificate. Point the `reverb` domain row at `ws.example.com` with
Path `/`, then set:

```dotenv
REVERB_HOST_PUBLIC=ws.example.com
REVERB_PORT_PUBLIC=443
REVERB_SCHEME_PUBLIC=https
REVERB_ALLOWED_ORIGINS=chat.example.com
```

`REVERB_ALLOWED_ORIGINS` stays the **app's** host in both layouts. It is checked
against the browser's `Origin` header, which is the page the socket was opened
from, not the host the socket connects to. Leaving it at the template's
`chat.example.com` when your host is something else rejects every handshake
silently, and all you see is a
[stuck "Reconnecting…" banner](#a-stuck-reconnecting-banner).

## Deploy

Hit **Deploy**. The `app` container runs `php artisan migrate --force` on boot,
so the database is ready by the time it reports healthy. Then
[create the first user and workspace](/self-hosting/first-user/).

## Running artisan commands

There is no `docker compose exec` here, because the compose project belongs to
Dokploy. Go through the container directly:

```bash
docker ps --filter name=app --format '{{.Names}}'
docker exec -it <project>-app-1 php artisan about
```

The image already sets the working directory to `/app` and runs as the `www`
user, so neither `-w` nor `-u` is needed.

## Pinning and upgrading

`APP_VERSION` selects the image tag, and upgrading is an edit in the Environment
tab followed by **Redeploy** (Dokploy pulls the new tag):

```dotenv
APP_VERSION=1.15.2 # x-release-please-version
```

To track stable releases instead of pinning one, set `APP_IMAGE`, which overrides
the version entirely:

```dotenv
APP_IMAGE=ghcr.io/deskhq/the-desk:latest
```

`latest` is only ever applied to a stable release. A release candidate is
published as `X.Y.Z-rc.N` plus the moving `rc` tag, so a candidate can never land
on `latest` and this cannot drift onto the `develop` line by accident.

:::caution[Auto-deploy on tags fires for candidates too]
Trigger Type **On Tag** fires for every `v*` tag in the repository, and that
includes the `vX.Y.Z-rc.N` candidates cut from `develop`. If you only want stable
releases, pin `APP_VERSION` and redeploy by hand, or trigger on pushes to
`master`.
:::

See [Upgrading](/self-hosting/upgrading/) for what an upgrade does to your data
and the search index.

## Troubleshooting

### A bare `404 page not found` from Traefik

**Symptom.** The domain resolves and serves TLS, but every request returns a
plain `404 page not found` with no styling. That page is Traefik's, not the
app's.

**Cause.** Traefik drops the router for a container that is not running, so a
crash-looping `app` container looks like a missing route. The wrong **Container
Port** produces the same page.

**Fix.** Read the container logs, which name the real failure:

```bash
docker logs <project>-app-1 --tail=100
```

Then confirm Container Port is `8080` on both domain rows.

### `could not translate host name "pgsql" to address`

**Symptom.** The `app` container restarts in a loop with
`SQLSTATE[08006] could not translate host name "pgsql" to address`, while
`pgsql` itself is up and healthy.

**Cause.** The stack is split across two networks. Dokploy rewrites `networks:`
on whichever service a domain is attached to, replacing a custom network with
`default` plus `dokploy-network`. Every other service stays on the custom one, so
the web container can no longer resolve its own database.

**Fix.** Use `docker-compose.dokploy.yml`, which declares no custom network.
If you pasted a compose file of your own, remove any `networks:` block that is
not `dokploy-network`.

### `Failed to parse dotenv file. Encountered unexpected whitespace`

**Symptom.** Containers restart in a loop. The error names neither the file nor
the key, and the deployment log usually scrolls past it.

**Cause.** A value in the Environment tab contains a space. The editor stripped
the quotes that made it parse.

**Fix.** Find the value with a space in it and remove the space, for example
`APP_NAME=TheDesk`. `SSO_OIDC_SCOPES` is the one setting that cannot be written
without spaces, so leave it commented out unless your identity provider needs
non-default scopes.

### A stuck "Reconnecting…" banner

**Symptom.** The app loads and you can sign in, but a **"Reconnecting…"** banner
sits at the top and no real-time updates arrive.

**Cause.** On this platform it is almost always one of two things: the `reverb`
domain row with Path `/app` is missing (or has Strip Path on), or
`REVERB_ALLOWED_ORIGINS` is still the template's `chat.example.com` while your
host is something else, in which case Reverb rejects every handshake without
saying so.

**Fix.** Re-check the [Domains table](#the-domains-tab) and the
`REVERB_ALLOWED_ORIGINS` value for your layout, then redeploy. The full
symptom-first walkthrough is in
[Troubleshooting](/self-hosting/troubleshooting/#reconnecting-after-login--websocket-wont-connect).

:::caution
`.env` changes need the containers **recreated**, not just restarted, because the
config cache is built at boot. Dokploy's **Redeploy** does that. See
[Changed `.env` but nothing changed](/self-hosting/troubleshooting/#changed-env-but-nothing-changed).
:::
