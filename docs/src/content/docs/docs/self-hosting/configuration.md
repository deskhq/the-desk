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

## Single sign-on (LDAP / Active Directory)

To authenticate members against an on-prem LDAP or Active Directory server, point
the app at your directory and a read-only service (bind) account:

```bash
LDAP_HOST=ldap.example.com
LDAP_PORT=389
LDAP_BASE_DN="dc=example,dc=com"
LDAP_USERNAME="cn=readonly,dc=example,dc=com"
LDAP_PASSWORD=your-service-account-password
# Encrypt the connection in production:
LDAP_TLS=true          # LDAPS (usually port 636)
# LDAP_STARTTLS=true   # or upgrade a plain connection instead
```

Members then sign in with their directory credentials on the normal login form
(the app **binds** to verify them — no browser redirect). The first login
just-in-time provisions the account into the default team as a Member, matched to
an existing user by the directory's **mail** attribute and keyed by its stable
**objectGUID**; the mapped display name syncs on every login.

By default members sign in with their **email** and the display name comes from
`cn`. If your directory uses different attributes — for example Active Directory
where users log in with `sAMAccountName` — remap them:

```bash
LDAP_ATTR_USERNAME=samaccountname   # what members type on the login form
LDAP_ATTR_NAME=displayname          # attribute used as the app display name
LDAP_ATTR_GUID=objectguid           # objectguid (AD) or entryuuid (OpenLDAP)
```

See [Environment variables → Single sign-on (LDAP)](/docs/reference/environment-variables/#single-sign-on-ldap--active-directory)
for every attribute mapping, and [Feature toggles → SSO-only mode](/docs/reference/feature-toggles/#sso-only-mode)
to require directory login.

## Directory provisioning (SCIM 2.0)

The sign-on options above provision an account the first time someone logs in. To
also have accounts **deactivated automatically** when someone is removed from the
directory, enable the **SCIM 2.0** endpoint and let your identity provider push
lifecycle changes to it. Set a bearer token:

```bash
SCIM_TOKEN=a-long-random-secret   # blank keeps the endpoint off
# SCIM_BASE_PATH=/scim            # change the base path if you need to
```

Then, in your IdP's provisioning settings, point it at:

```
SCIM base URL:  https://your-desk.example.com/scim/v2
Auth:           HTTP header — Authorization: Bearer a-long-random-secret
```

The IdP then keeps accounts in sync automatically:

- **Create** matches an existing account by email or provisions a new one into the
  default team as a Member — the same rules as OIDC/LDAP.
- **Deactivate** (`active: false`, or a `DELETE`) **tombstones** the account: its
  sessions end immediately and it can no longer sign in, but its messages and
  history are kept rather than hard-deleted.
- **Reactivate** (`active: true`) restores access.

Members are matched on `userName`, which should be their email.

:::caution
The token is a full provisioning credential — anyone with it can create and
deactivate accounts. Keep it secret, only expose SCIM over HTTPS, and rotate it if
it leaks. Leaving `SCIM_TOKEN` blank means the endpoint does not exist at all.
:::

See [Environment variables → Directory provisioning (SCIM 2.0)](/docs/reference/environment-variables/#directory-provisioning-scim-20)
for the full reference.

## Applying changes

After editing `.env`, restart the stack to pick up the new values:

```bash
docker compose -f docker-compose.prod.yml up -d
```
