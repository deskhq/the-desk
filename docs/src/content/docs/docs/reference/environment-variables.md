---
title: Environment variables
description: Reference for the .env variables that matter when self-hosting The Desk.
---

Every setting is read from `.env` at **runtime**. This reference covers the
variables that matter when self-hosting. On/off feature switches have their own
page: [Feature toggles](/docs/reference/feature-toggles/).

:::note
Run `./docker/gen-secrets.sh` to generate the required secrets — it fills
`APP_KEY`, `DB_PASSWORD`, `MEILISEARCH_KEY`, and the `REVERB_*` app credentials
with fresh random values and never overwrites values you have already set.
:::

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
| `APP_PORT` | `80`           | Host port the web app is published on.            |
| `APP_IMAGE`| `the-desk:latest` | Set to `ghcr.io/emmpaul/the-desk:X.Y.Z` to run the published image instead of building. |

## Database

| Variable      | Default   | Notes                          |
| ------------- | --------- | ------------------------------ |
| `DB_DATABASE` | `laravel` | PostgreSQL database name.       |
| `DB_USERNAME` | `laravel` | PostgreSQL user.                |
| `DB_PASSWORD` | —         | Required secret (see above).    |

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
| `MEILISEARCH_VERSION`      | `v1.49`  | Pins the image tag **and** the version-scoped data volume. See [Upgrading](/docs/self-hosting/upgrading/#search-reindexing). |
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
production — see [Configuration](/docs/self-hosting/configuration/#reverb-websockets--mind-the-browser-vs-server-split).

| Variable               | Set to        | Notes                                             |
| ---------------------- | ------------- | ------------------------------------------------- |
| `REVERB_SCHEME_PUBLIC` | `https`       | Browser connects over TLS.                        |
| `REVERB_PORT_PUBLIC`   | `443`         | Your proxy terminates `wss` on 443.               |
| `REVERB_HOST_PUBLIC`   | *(APP_URL host)* | Only set for a dedicated WebSocket subdomain.  |

## Feature toggles

| Variable                     | Default | See                                            |
| ---------------------------- | ------- | ---------------------------------------------- |
| `REGISTRATION_ENABLED`       | `true`  | [Feature toggles → Open registration](/docs/reference/feature-toggles/#open-registration) |
| `EMAIL_VERIFICATION_ENABLED` | `false` | [Feature toggles → Email verification](/docs/reference/feature-toggles/#email-verification) |
| `GRAVATAR_ENABLED`           | `true`  | [Feature toggles → Gravatar avatars](/docs/reference/feature-toggles/#gravatar-avatars) |
| `ACTIVITYLOG_ENABLED`        | `true`  | [Feature toggles → Activity logging](/docs/reference/feature-toggles/#activity-logging) |
| `REVERB_SCALING_ENABLED`     | `false` | [Feature toggles → Advanced Reverb](/docs/reference/feature-toggles/#advanced-reverb-options) |

## Attachments

Files and images members attach to messages.

| Variable                       | Default | Notes                                                                 |
| ------------------------------ | ------- | --------------------------------------------------------------------- |
| `ATTACHMENT_MAX_SIZE_MB`       | `25`    | Largest single file a member can upload, in megabytes.                |
| `ATTACHMENT_MAX_PER_MESSAGE`   | `10`    | Most files that can ride a single message.                            |
| `ATTACHMENT_PENDING_TTL_HOURS` | `24`    | How long an uploaded-but-never-sent file is kept before it is swept.  |

:::caution[Raising the size limit needs matching server limits]
`ATTACHMENT_MAX_SIZE_MB` only controls the app's own validation, which runs **after** the whole
file has been received. To actually accept larger uploads you must also raise, to at least the same
size:

- PHP's `upload_max_filesize` **and** `post_max_size`, and
- any reverse-proxy body-size limit in front of the app (for nginx, `client_max_body_size`).

If these are lower than `ATTACHMENT_MAX_SIZE_MB`, large uploads are rejected by the server or proxy
before the app ever sees them. See [Reverse proxy](/docs/self-hosting/reverse-proxy/).
:::
