---
title: Configuration
description: Configure your instance through .env — app URL, mail, and the browser-vs-server Reverb split. Every setting is read at runtime.
---

Every setting is read from `.env` at **runtime**, so changing a value and
restarting the affected containers applies it — no rebuild is needed. This page
covers the settings you must set by hand after running `./docker/gen-secrets.sh`
(which handles the [required secrets](/docs/self-hosting/installation/#required-secrets)).

For the full list of variables, see the
[Environment variables reference](/docs/reference/environment-variables/). For
on/off feature switches, see [Feature toggles](/docs/reference/feature-toggles/).

## Application

| Variable    | What it does                                                       |
| ----------- | ------------------------------------------------------------------ |
| `APP_URL`   | The public URL of your instance (e.g. `https://chat.example.com`). |
| `APP_NAME`  | The app name shown in the UI and emails (default `The Desk`).      |
| `APP_PORT`  | Host port the web app is published on (default `80`).              |

`APP_NAME` and the browser-facing Reverb settings are served to the frontend at
runtime, which is why one published image works for any host.

## Mail (SMTP)

The Desk sends workspace invitations (and email verification, if you
[enable it](/docs/reference/feature-toggles/#email-verification)), so SMTP must work.
Set the `MAIL_*` variables to your provider's credentials:

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=postmaster@example.com
MAIL_PASSWORD=your-smtp-password
MAIL_FROM_ADDRESS="chat@example.com"
MAIL_FROM_NAME="${APP_NAME}"
```

## Reverb (WebSockets) — mind the browser vs. server split

Reverb powers real-time updates. The setting that trips people up is that the
**container** and the **browser** reach Reverb differently:

- The container speaks plain `http` on `8080` — `REVERB_PORT` / `REVERB_SCHEME`.
- The browser reaches Reverb through your **TLS proxy** on `wss` / `443`.

So set the browser-facing (`*_PUBLIC`) values accordingly:

```dotenv
REVERB_PORT_PUBLIC=443
REVERB_SCHEME_PUBLIC=https
# The browser-facing host defaults to your APP_URL host. Override only if you
# serve Reverb from a dedicated WebSocket subdomain:
# REVERB_HOST_PUBLIC=ws.example.com
```

These are read at runtime, so a restart applies changes — no rebuild.

:::caution
Your reverse proxy **must forward WebSocket upgrade requests** to the `reverb`
service, or real-time features silently stop working. See
[Reverse proxy & TLS](/docs/self-hosting/reverse-proxy/).
:::

## Search (Meilisearch)

`MEILISEARCH_KEY` is a required secret (generated for you). `MEILISEARCH_VERSION`
pins both the image tag and the version-scoped data volume — see
[Upgrading](/docs/self-hosting/upgrading/#search-reindexing) for why that matters.

## Single sign-on (OpenID Connect)

To let members sign in through your identity provider (Okta, Microsoft Entra ID,
Google Workspace, Auth0, Keycloak, …), register an OAuth application there with
the redirect URI `https://your-host/auth/oidc/callback`, then set:

```bash
SSO_OIDC_ISSUER=https://your-idp.example.com
SSO_OIDC_CLIENT_ID=your-client-id
SSO_OIDC_CLIENT_SECRET=your-client-secret
```

A "Sign in with SSO" button appears on the login page. The first login
just-in-time provisions the account into the default team as a Member (matched to
an existing user by verified email). For the full list of options — default team,
scopes, and routing **all** access through the directory with `AUTH_SSO_ONLY` —
see [Environment variables → Single sign-on](/docs/reference/environment-variables/#single-sign-on-openid-connect)
and [Feature toggles → SSO-only mode](/docs/reference/feature-toggles/#sso-only-mode).

## Applying changes

After editing `.env`, restart the stack to pick up the new values:

```bash
docker compose -f docker-compose.prod.yml up -d
```
