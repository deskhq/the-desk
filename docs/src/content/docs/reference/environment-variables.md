---
title: Environment variables
description: Reference for the .env variables that matter when self-hosting The Desk.
---

Every setting is read from `.env` at **runtime**. This reference covers the
variables that matter when self-hosting. On/off feature switches have their own
page: [Feature toggles](/reference/feature-toggles/).

:::note
Run `./docker/gen-secrets.sh` to generate the required secrets — it fills
`APP_KEY`, `DB_PASSWORD`, `MEILISEARCH_KEY`, and the `REVERB_*` app credentials
with fresh random values and never overwrites values you have already set.
:::

## Docker Compose

One variable in `.env` is read by the **`docker compose` CLI itself** rather than
by the app:

| Variable       | Production template value | Notes                                                                 |
| -------------- | ------------------------- | --------------------------------------------------------------------- |
| `COMPOSE_FILE` | `docker-compose.prod.yml` | Which compose file(s) a bare `docker compose` resolves. This is the value `.env.prod.example` ships, not a Compose default: with it **unset**, a bare `docker compose` picks up `compose.yaml`, the development stack. Setting it is why no command on this site passes `-f docker-compose.prod.yml`. Building from source? List both files separated by a colon: `docker-compose.prod.yml:docker-compose.build.yml`. |

See [the COMPOSE_FILE variable](/self-hosting/installation/#the-compose_file-variable)
for the caveat about a bare `docker compose down`, and for what to do if your
`.env` predates this variable.

## Required secrets

The stack **refuses to start** without these (no defaults):

| Variable          | Notes                                                        |
| ----------------- | ------------------------------------------------------------ |
| `APP_KEY`         | A `base64:`-encoded 32-byte key. Generated for you.          |
| `DB_PASSWORD`     | PostgreSQL password. Generated for you.                      |
| `MEILISEARCH_KEY` | Meilisearch master key. Generated for you.                   |

## Application

| Variable   | Default        | Notes                                             |
| ---------- | -------------- | ------------------------------------------------- |
| `APP_URL`  | —              | Public URL of your instance. **Set this.**        |
| `APP_NAME` | `The Desk`     | Shown in the UI and emails. Served at runtime.    |
| `APP_PORT` | `8000`         | Host port the web app is published on (bound to `APP_BIND`). |
| `APP_BIND` | `127.0.0.1`    | Address the published app/reverb ports bind to. `0.0.0.0` exposes the raw HTTP origin off-box. |
| `APP_VERSION` | — **(required)** | The release to run. The compose file pins the image to `ghcr.io/deskhq/the-desk:$APP_VERSION`, so upgrading is an `APP_VERSION` bump plus `docker compose pull && docker compose up -d` — no git checkout. It has no default: `up -d` fails fast with a clear message if it is unset. |
| `APP_IMAGE`| *(uses `APP_VERSION`)* | Full image override. Set it to run a tag on another registry (a fork, an air-gapped mirror) or a floating tag like `edge`. When set it wins completely and `APP_VERSION` is ignored. |

## Database

| Variable      | Default   | Notes                          |
| ------------- | --------- | ------------------------------ |
| `DB_DATABASE` | `laravel` | PostgreSQL database name.       |
| `DB_USERNAME` | `laravel` | PostgreSQL user.                |
| `DB_PASSWORD` | —         | Required secret (see above).    |

## Queue workers

Background work — real-time broadcasts, mail, link previews, webhook delivery,
exports — runs through Redis on the `queue` and `queue-broadcasts` services (see
[Architecture](/reference/architecture/#why-broadcasts-get-their-own-worker)).
Neither needs configuring; the one tunable is how a worker waits for work.

| Variable                 | Default | Notes                                                                                            |
| ------------------------ | ------- | -------------------------------------------------------------------------------------------------- |
| `REDIS_QUEUE_BLOCK_FOR`  | `1`     | Seconds a worker holds a blocking read on Redis open before it looks again. A job on the worker's **first** queue starts the instant it is dispatched however high this is, so raising it never slows real-time updates. What it does set is how long a job on a **secondary** queue can sit before the worker rechecks it — on the shared `queue` worker, that is `default`, so raising this to `10` can leave mail or a link preview waiting up to ten seconds to start. Values below `1` are floored to `1`: the underlying Redis command reads `0` as "wait forever", which would strand everything but broadcasts. |

## Mail (SMTP)

| Variable            | Notes                                        |
| ------------------- | -------------------------------------------- |
| `MAIL_MAILER`       | `smtp` for a real mail server.               |
| `MAIL_HOST`         | SMTP host.                                   |
| `MAIL_PORT`         | SMTP port (commonly `587`).                  |
| `MAIL_USERNAME`     | SMTP username.                               |
| `MAIL_PASSWORD`     | SMTP password.                               |
| `MAIL_FROM_ADDRESS` | From address on outgoing mail.               |
| `MAIL_FROM_NAME`    | From name (defaults to `${APP_NAME}`).       |

## Search (Meilisearch)

| Variable                   | Default  | Notes                                                    |
| -------------------------- | -------- | -------------------------------------------------------- |
| `MEILISEARCH_KEY`          | —        | Required secret (master key).                            |
| `MEILISEARCH_VERSION`      | `v1.49`  | Pins the image tag **and** the version-scoped data volume. See [Upgrading](/self-hosting/upgrading/#search-reindexing). |
| `MEILISEARCH_NO_ANALYTICS` | `true`   | Disable Meilisearch usage analytics.                     |

## Reverb — server-facing (container)

How the containers reach Reverb. Defaults are correct for the bundled stack.

| Variable         | Default  | Notes                              |
| ---------------- | -------- | ---------------------------------- |
| `REVERB_APP_ID`  | —        | Reverb app id. Generated for you.  |
| `REVERB_APP_KEY` | —        | Reverb app key. Generated for you. |
| `REVERB_APP_SECRET` | —     | Reverb app secret. Generated for you. |
| `REVERB_HOST`    | `reverb` | Internal service host.             |
| `REVERB_PORT`    | `8080`   | Internal (and published) Reverb port. |
| `REVERB_SCHEME`  | `http`   | The container speaks plain HTTP.   |

## Reverb — browser-facing (public)

How the **browser** reaches Reverb through your TLS proxy. Set these for
production — see [Configuration](/self-hosting/configuration/#reverb-websockets--mind-the-browser-vs-server-split).

| Variable               | Set to        | Notes                                             |
| ---------------------- | ------------- | ------------------------------------------------- |
| `REVERB_SCHEME_PUBLIC` | `https`       | Browser connects over TLS.                        |
| `REVERB_PORT_PUBLIC`   | `443`         | Your proxy terminates `wss` on 443.               |
| `REVERB_HOST_PUBLIC`   | *(APP_URL host)* | Only set for a dedicated WebSocket subdomain.  |
| `REVERB_ALLOWED_ORIGINS` | `*`         | Comma-separated origins allowed to open a WebSocket connection. Lock to the app host in production (e.g. `chat.example.com`). |

## Feature toggles

| Variable                     | Default | See                                            |
| ---------------------------- | ------- | ---------------------------------------------- |
| `REGISTRATION_ENABLED`       | `true`  | [Feature toggles → Open registration](/reference/feature-toggles/#open-registration) |
| `EMAIL_VERIFICATION_ENABLED` | `false` | [Feature toggles → Email verification](/reference/feature-toggles/#email-verification) |
| `TWO_FACTOR_AUTH_ENABLED`    | `false` | [Feature toggles → Two-factor authentication](/reference/feature-toggles/#two-factor-authentication) |
| `PASSKEYS_ENABLED`           | `false` | [Feature toggles → Passkeys](/reference/feature-toggles/#passkeys) |
| `GRAVATAR_ENABLED`           | `true`  | [Feature toggles → Gravatar avatars](/reference/feature-toggles/#gravatar-avatars) |
| `ACTIVITYLOG_ENABLED`        | `true`  | [Feature toggles → Activity logging](/reference/feature-toggles/#activity-logging) |
| `REVERB_SCALING_ENABLED`     | `false` | [Feature toggles → Advanced Reverb](/reference/feature-toggles/#advanced-reverb-options) |
| `AUTH_SSO_ONLY`              | `false` | [Feature toggles → SSO-only mode](/reference/feature-toggles/#sso-only-mode) |
| `UPDATE_CHECK_ENABLED`       | `true`  | [Feature toggles → Update checks](/reference/feature-toggles/#update-checks) |
| `POLLS_ENABLED`              | `true`  | [Feature toggles → Polls](/reference/feature-toggles/#polls) |
| `INTEGRATIONS_ENABLED`       | `true`  | [Feature toggles → Integrations platform](/reference/feature-toggles/#integrations-platform) |
| `INTEGRATIONS_API_RATE_LIMIT`| `60`    | [Feature toggles → Integrations platform](/reference/feature-toggles/#integrations-platform) |
| `WEBHOOKS_MAX_ATTEMPTS`      | `5`     | [Feature toggles → Integrations platform](/reference/feature-toggles/#outgoing-webhooks) |
| `WEBHOOKS_TIMEOUT`           | `5`     | [Feature toggles → Integrations platform](/reference/feature-toggles/#outgoing-webhooks) |
| `WEBHOOKS_DISABLE_AFTER`     | `5`     | [Feature toggles → Integrations platform](/reference/feature-toggles/#outgoing-webhooks) |
| `WEBHOOKS_BLOCK_PRIVATE_URLS`| `true`  | [Feature toggles → Integrations platform](/reference/feature-toggles/#outgoing-webhooks) |
| `DEMO_MODE`                  | `false` | [Feature toggles → Demo mode](/reference/feature-toggles/#demo-mode) |
| `CSP_ENABLED`                | `true`  | [Feature toggles → Content Security Policy](/reference/feature-toggles/#content-security-policy) |
| `CSP_REPORT_ONLY`            | `false` | [Feature toggles → Content Security Policy](/reference/feature-toggles/#content-security-policy) |
| `CSP_FRAME_ANCESTORS`        | `none`  | [Feature toggles → Clickjacking protection](/reference/feature-toggles/#clickjacking-protection) |
| `HSTS_ENABLED`               | `true`  | [Feature toggles → HTTPS enforcement (HSTS)](/reference/feature-toggles/#https-enforcement-hsts) |
| `HSTS_MAX_AGE`               | `31536000` | [Feature toggles → HTTPS enforcement (HSTS)](/reference/feature-toggles/#https-enforcement-hsts) |
| `HSTS_INCLUDE_SUBDOMAINS`    | `true`  | [Feature toggles → HTTPS enforcement (HSTS)](/reference/feature-toggles/#https-enforcement-hsts) |
| `HSTS_PRELOAD`               | `false` | [Feature toggles → HTTPS enforcement (HSTS)](/reference/feature-toggles/#https-enforcement-hsts) |

## Content Security Policy

Comma-separated origins **appended** to the shipped policy, for a script,
stylesheet, image host, API, embedded frame or font provider of your own. They
are additive only: none of them can remove the script nonce or
`'strict-dynamic'`. See
[Feature toggles → Content Security Policy](/reference/feature-toggles/#content-security-policy).

| Variable                | Default | Adds to       |
| ----------------------- | ------- | ------------- |
| `CSP_EXTRA_SCRIPT_SRC`  | *(none)* | `script-src`  |
| `CSP_EXTRA_STYLE_SRC`   | *(none)* | `style-src`   |
| `CSP_EXTRA_IMG_SRC`     | *(none)* | `img-src`     |
| `CSP_EXTRA_CONNECT_SRC` | *(none)* | `connect-src` |
| `CSP_EXTRA_FRAME_SRC`   | *(none)* | `frame-src`   |
| `CSP_EXTRA_FONT_SRC`    | *(none)* | `font-src`    |

A stylesheet origin belongs in `CSP_EXTRA_STYLE_SRC` and a font-file origin in
`CSP_EXTRA_FONT_SRC`, independently. Google Fonts serves the two from different
hosts, so it needs both:

```bash
CSP_EXTRA_STYLE_SRC="https://fonts.googleapis.com"
CSP_EXTRA_FONT_SRC="https://fonts.gstatic.com"
```

There, one without the other still fails. A provider serving both from one
origin needs that origin in both keys, and a font referenced from your own CSS
needs only `CSP_EXTRA_FONT_SRC`. The app self-hosts its own fonts, so you only
need this if you deliberately add a web font of your own.

One more key controls who may embed the app **in** a frame, rather than what the
app may load:

| Variable               | Default | Effect                                                                 |
| ---------------------- | ------- | ---------------------------------------------------------------------- |
| `CSP_FRAME_ANCESTORS`  | `none`  | Sends `frame-ancestors` and `X-Frame-Options`. See [Feature toggles → Clickjacking protection](/reference/feature-toggles/#clickjacking-protection). |

## Session cookies

| Variable                | Default                         | Notes                                                                                              |
| ----------------------- | ------------------------------- | -------------------------------------------------------------------------------------------------- |
| `SESSION_SECURE_COOKIE` | *(derived from `APP_URL`)*      | Adds the `Secure` flag, so the browser never sends the session cookie over plain HTTP. Defaults to `true` when `APP_URL` starts with `https://`, `false` otherwise. |
| `SESSION_LIFETIME`      | `120`                           | Minutes of inactivity before a session expires.                                                     |
| `SESSION_ENCRYPT`       | `false`                         | Encrypt session payloads at rest in Redis.                                                          |
| `SESSION_DOMAIN`        | `null`                          | Cookie domain. Leave unset unless you deliberately share the cookie across subdomains.              |

You only need to set `SESSION_SECURE_COOKIE` explicitly if the scheme in
`APP_URL` does not describe how browsers actually reach the app — for example
when TLS is terminated at a hostname other than the one `APP_URL` names. Setting
it to `true` on a deployment served over plain HTTP means the browser will never
return the cookie and nobody can stay signed in.

## Single sign-on (OpenID Connect)

Let members authenticate through your identity provider (Okta, Microsoft Entra
ID, Google Workspace, Auth0, Keycloak, …). The app reads the provider's discovery
document at `{issuer}/.well-known/openid-configuration` to find its endpoints, so
only the issuer, client id, and secret are required. The first SSO login
**just-in-time provisions** the account — matched to an existing user by verified
email, otherwise created — into the default team as a **Member**. Leave
`SSO_OIDC_CLIENT_ID` / `SSO_OIDC_ISSUER` blank to keep SSO off (no button shown).

| Variable                 | Default                          | Notes                                                                     |
| ------------------------ | -------------------------------- | ------------------------------------------------------------------------- |
| `SSO_OIDC_ISSUER`        | *(blank)*                        | Your provider's issuer URL. Discovery is read from `{issuer}/.well-known/openid-configuration`. |
| `SSO_OIDC_CLIENT_ID`     | *(blank)*                        | The OAuth client id registered at your provider.                          |
| `SSO_OIDC_CLIENT_SECRET` | *(blank)*                        | The client secret.                                                        |
| `SSO_OIDC_REDIRECT_URI`  | `${APP_URL}/auth/oidc/callback`  | Callback URI; must match what you register at the IdP.                     |
| `SSO_OIDC_DISCOVERY_URL` | *(derived from issuer)*          | Override only if discovery is not at the standard well-known path.         |
| `SSO_OIDC_SCOPES`        | `openid profile email`           | Space-separated OIDC scopes to request.                                   |
| `SSO_OIDC_VALIDATE_ID_TOKEN` | `true`                       | When the provider returns an `id_token`, verify its signature (via the provider JWKS), issuer, audience, and expiry, and require its subject to match the UserInfo subject. Defence-in-depth; set `false` only for a non-conformant provider whose `id_token` cannot be validated. |
| `SSO_OIDC_REQUIRE_VERIFIED_EMAIL` | `false`                 | A login whose UserInfo reports `email_verified: false` is **always** rejected — never linked to an existing account nor provisioned. Set `true` to also reject logins whose UserInfo **omits** the claim entirely (fail-closed). Off by default because many conformant IdPs never send the claim. |
| `SSO_DEFAULT_TEAM_ID`    | *(sole team)*                    | Team new SSO users join as a Member. Blank uses the sole team when there is exactly one; otherwise the account gets its own workspace. Shared with LDAP. |
| `AUTH_SSO_ONLY`          | `false`                          | Route **all** access through SSO (OIDC or LDAP). See [SSO-only mode](/reference/feature-toggles/#sso-only-mode). |

## Single sign-on (LDAP / Active Directory)

Authenticate members against an on-prem **LDAP / Active Directory** directory.
Unlike OIDC's browser redirect, users enter their directory credentials in the
app's own login form and the app **binds** to the directory to verify them. On a
successful bind the entry is matched to an app user by its **mail** attribute,
keyed by its stable **objectGUID**, and — like OIDC — **just-in-time provisioned**
into the default team as a **Member** with a verified email. The mapped display
name is **synced on every login**. Leave `LDAP_HOST` / `LDAP_BASE_DN` blank to keep
LDAP off.

Directory login sits alongside the local password form by default, so a
break-glass password account survives a directory outage. `AUTH_SSO_ONLY=true`
engages once LDAP is configured too, disabling the local-password path while still
allowing the directory bind.

| Variable             | Default                       | Notes                                                                                             |
| -------------------- | ----------------------------- | ------------------------------------------------------------------------------------------------- |
| `LDAP_HOST`          | *(blank)*                     | Directory host. Blank (with `LDAP_BASE_DN`) keeps LDAP off.                                        |
| `LDAP_PORT`          | `389`                         | `389` for plain/STARTTLS, `636` for LDAPS.                                                         |
| `LDAP_BASE_DN`       | *(blank)*                     | Base DN searched for the user, e.g. `dc=example,dc=com`.                                           |
| `LDAP_USERNAME`      | *(blank)*                     | DN of the service (bind) account used to search before binding as the user.                       |
| `LDAP_PASSWORD`      | *(blank)*                     | Password for the service account.                                                                 |
| `LDAP_TLS`           | `false`                       | Use LDAPS (encrypted transport, usually port 636).                                                |
| `LDAP_STARTTLS`      | `false`                       | Upgrade a plain connection with STARTTLS.                                                          |
| `LDAP_TIMEOUT`       | `5`                           | Connection timeout in seconds.                                                                     |
| `LDAP_ATTR_USERNAME` | `mail`                        | Directory attribute matched against the login form value. Set to e.g. `samaccountname` to sign in with a directory username instead of email. |
| `LDAP_ATTR_MAIL`     | `mail`                        | Directory attribute used as the app email (how users are matched/linked).                         |
| `LDAP_ATTR_NAME`     | `cn`                          | Directory attribute synced to the app display name on every login.                                |
| `LDAP_ATTR_GUID`     | `objectguid`                  | Stable identity attribute. `objectguid` for Active Directory, `entryuuid` for OpenLDAP.           |
| `LDAP_CONNECTION`    | `default`                     | Name of the connection in `config/ldap.php` to use.                                               |

## Directory provisioning (SCIM 2.0)

Let your identity provider (Okta, Entra ID, OneLogin, …) **push** user lifecycle
changes over **SCIM 2.0**, so removing someone from the directory automatically
deactivates their account here. This is separate from the login paths above: it
is a bearer-token REST API the IdP calls, not a form users sign in through.

Point your IdP at `${APP_URL}/scim/v2` and authenticate it with `SCIM_TOKEN`.
**Creates** match or just-in-time provision the user through the same rules as
OIDC/LDAP (email match or create, default team as **Member**). **Deactivations**
(`active: false` or `DELETE`) **tombstone** the account — access is revoked and
every session ends, but history is kept, not hard-deleted — and a later
`active: true` **reactivates** it. Leave `SCIM_TOKEN` blank to keep the endpoint
off (it is not mounted at all without a token).

| Variable         | Default  | Notes                                                                                          |
| ---------------- | -------- | ---------------------------------------------------------------------------------------------- |
| `SCIM_TOKEN`     | *(blank)* | Bearer token the IdP presents on every SCIM request. Blank keeps the endpoint off. Use a long random secret. |
| `SCIM_BASE_PATH` | `/scim`  | Route **prefix** the SCIM API mounts under; the versioned resources sit beneath it. With the default, the IdP base URL is `${APP_URL}/scim/v2` and users live at `/scim/v2/Users`. |

:::caution
`SCIM_TOKEN` is a full provisioning credential — anyone holding it can create and
deactivate accounts. Keep it secret, serve SCIM over HTTPS only, and rotate it if
it leaks.
:::

## Attachments

Files and images members attach to messages.

| Variable                       | Default | Notes                                                                 |
| ------------------------------ | ------- | --------------------------------------------------------------------- |
| `ATTACHMENT_MAX_SIZE_MB`       | `25`    | Largest single file a member can upload, in megabytes.                |
| `ATTACHMENT_MAX_PER_MESSAGE`   | `10`    | Most files that can ride a single message.                            |
| `ATTACHMENT_PENDING_TTL_HOURS` | `24`    | How long an uploaded-but-never-sent file is kept before it is swept.  |
| `ATTACHMENT_DISK`              | `local` | Private disk files are stored on. Point at a configured S3 disk for bucket storage. |
| `ATTACHMENT_IMAGE_DRIVER`      | `imagick` | Image library used to strip EXIF metadata and build thumbnails: `imagick` or `gd`. |
| `ATTACHMENT_THUMBNAIL_MAX_PX`  | `720`   | Longest edge, in pixels, of a generated image thumbnail. Images are only scaled down. |

:::note[Image processing needs a PHP image extension]
Uploaded images have their EXIF metadata stripped (so photo GPS never leaks) and a thumbnail
generated for the timeline. This needs the **Imagick** and **GD** PHP extensions — both are declared
in `composer.json` and shipped in the bundled production image. `ATTACHMENT_IMAGE_DRIVER` selects
which one processes images: `imagick` by default, `gd` as an alternative.
:::

:::caution[Raising the size limit needs matching server limits]
`ATTACHMENT_MAX_SIZE_MB` only controls the app's own validation, which runs **after** the whole
file has been received. To actually accept larger uploads you must also raise these — and give them a
little headroom above the cap, since multipart form encoding adds overhead on
top of the raw file (this matters most for `post_max_size`, which bounds the
whole request body, not just the file):

- PHP's `upload_max_filesize` **and** `post_max_size`, and
- any reverse-proxy body-size limit in front of the app (for nginx, `client_max_body_size`).

If these are lower than `ATTACHMENT_MAX_SIZE_MB`, large uploads are rejected by the server or proxy
before the app ever sees them. See [Reverse proxy](/self-hosting/reverse-proxy/).
:::

## GIFs (Giphy)

The composer's `/gif` picker searches [Giphy](https://developers.giphy.com/) and sends the chosen
GIF into any channel or DM. It is **off** until you supply an API key, so a default deployment ships
without it — the `/gif` command and picker are hidden, and the search/attach routes (though still
registered) return **404**.

| Variable               | Default   | Notes                                                                                     |
| ---------------------- | --------- | ----------------------------------------------------------------------------------------- |
| `GIPHY_API_KEY`        | *(blank)* | A Giphy API key (free from developers.giphy.com). Blank hides the feature entirely.       |
| `GIPHY_CONTENT_RATING` | `g`       | Strictest rating Giphy may return: `g`, `pg`, `pg-13`, or `r`. `g` is workplace-safe.     |

See [Feature toggles → GIF picker](/reference/feature-toggles/#gif-picker-giphy) for how the
feature behaves once enabled.

:::note[GIFs are hotlinked from Giphy's CDN]
A sent GIF is stored as a **reference** to Giphy's CDN URL — the bytes are never downloaded to your
server. Viewers' browsers therefore load the GIF directly from Giphy's CDN, which means Giphy can see
those requests (IP address, timing). This is a minor privacy consideration; self-hosting the GIF bytes
is not currently supported.
:::

## Session geolocation

The **Security** settings page can show an approximate location (city, country)
next to each active session, derived from its IP address. The lookup is fully
offline against a local **MaxMind GeoLite2 / GeoIP2 City** database — no
third-party API is called — and is **opt-in**: without a database file the
location segment is simply omitted, and it is always omitted for private, LAN, or
otherwise unresolvable addresses.

| Variable              | Default                                  | Notes                                                                                     |
| --------------------- | ---------------------------------------- | ----------------------------------------------------------------------------------------- |
| `GEOIP_DATABASE_PATH` | `storage/app/geoip/GeoLite2-City.mmdb`   | Absolute path to a `GeoLite2-City.mmdb` (or GeoIP2 City) database. Unset, the app resolves the shown default under `storage_path()`. A missing file → no locations shown. |

To enable it, download a free **GeoLite2 City** database from
[MaxMind](https://www.maxmind.com/en/geolite2/signup) (a free account is
required) and mount the `.mmdb` file at `GEOIP_DATABASE_PATH`. The database is not
bundled: MaxMind's licence does not allow redistribution, and it is refreshed
regularly, so you keep it up to date yourself.

## Presence

Every member sees a small dot beside their teammates' avatars: **filled** when
someone is active, a **hollow ring** when they are away, and none at all when
they are not connected. Away has two sources — a member can set it by hand from
the user menu (which persists until they unset it), and each browser tab reports
itself idle after a stretch with no pointer, keyboard, scroll, or focus activity.
Someone counts as away only once **every** device they are signed in on has gone
idle, so a laptop in use keeps them active however long a phone has been asleep.

| Variable                      | Default | Notes                                                                                                 |
| ----------------------------- | ------- | ------------------------------------------------------------------------------------------------------ |
| `PRESENCE_AWAY_AFTER_MINUTES` | `10`    | Minutes a tab may go without activity before it reports itself idle. Floored at `1`; there is no "never". |

Idle detection runs in the browser, so the threshold is served to each client
with the page rather than baked into the build — changing it takes effect on
every client's next page load, with no rebuild.

:::note[Presence needs no extra service]
Who is *connected* comes from the Reverb presence channel the app already uses;
active-versus-away is tracked in the cache (Redis in the bundled production
stack). Nothing is written to the database except a member's own manual away
setting, and if the cache is unavailable everyone simply reads as active — the
same as before the feature existed.
:::
