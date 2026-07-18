---
title: Feature toggles
description: Features you can turn on or off from .env — open registration, activity logging, and advanced Reverb options.
---

Several behaviours are switched from `.env`. Like all settings, they are read at
**runtime** — change the value and restart the stack to apply it, no rebuild
needed:

```bash
docker compose up -d
```

## Open registration

| Variable               | Default | Effect                          |
| ---------------------- | ------- | ------------------------------- |
| `REGISTRATION_ENABLED` | `true`  | Enables self-service sign-ups.  |

Public sign-ups are **on** by default. Set `REGISTRATION_ENABLED=false` to run a
**private / invite-only** instance: `/register` returns **404** and the "sign up"
links are hidden. Existing users and email invitations still work.

:::tip
Create your own account **before** turning registration off, then invite everyone
else. See [First user & workspace](/docs/self-hosting/first-user/#locking-down-registration).
:::

## Email verification

| Variable                     | Default | Effect                                                       |
| ---------------------------- | ------- | ------------------------------------------------------------ |
| `EMAIL_VERIFICATION_ENABLED` | `false` | Require new accounts to confirm their email before using the app. |

A single deploy-time flag for self-hosters. It defaults to **off**: registration
logs the new user straight in, and every account is treated as verified.

Set `EMAIL_VERIFICATION_ENABLED=true` to require confirmation. New accounts must
click the verification link before they can use the app, so your
[SMTP settings](/docs/self-hosting/configuration/#mail-smtp) **must work** or new users
will be stuck.

:::caution
Turning the flag on **re-gates existing accounts** that have no verified email on
their next request — they'll be prompted to verify. The verify routes are always
registered, so flipping the flag takes effect immediately with no data migration.
:::

## Two-factor authentication

| Variable                  | Default | Effect                                                       |
| ------------------------- | ------- | ------------------------------------------------------------ |
| `TWO_FACTOR_AUTH_ENABLED` | `false` | Let users add TOTP two-factor authentication to their account. |

A single deploy-time flag for self-hosters. It defaults to **off**, keeping
password-only sign-in.

Set `TWO_FACTOR_AUTH_ENABLED=true` to surface two-factor authentication under
**Settings → Security**. Each user can then enrol an authenticator app (a
time-based one-time code, or TOTP) and save single-use recovery codes; at the
next sign-in they're challenged for a code. Enrolment is **per-user and opt-in** —
turning the flag on offers the option but never forces anyone to enrol.

The toggle takes effect immediately with no data migration: the underlying routes
are always registered, so flipping the flag only changes whether the option is
offered.

:::note
This flag has **no effect under [SSO-only mode](#sso-only-mode)**. When
`AUTH_SSO_ONLY` routes everyone through an identity provider, that provider owns
multi-factor authentication, so the app-native option stays hidden.
:::

## Passkeys

| Variable           | Default | Effect                                              |
| ------------------ | ------- | --------------------------------------------------- |
| `PASSKEYS_ENABLED` | `false` | Let users sign in passwordlessly with WebAuthn passkeys. |

A single deploy-time flag for self-hosters. It defaults to **off**, keeping
password-only sign-in.

Set `PASSKEYS_ENABLED=true` to surface passkey management under **Settings →
Security** and a **"Sign in with a passkey"** button on the login screen. Each
user can then register one or more passkeys — Touch ID, Face ID, Windows Hello,
or a hardware security key — name them, and remove them (each change is
confirmed with their password). At the next sign-in they can authenticate with a
passkey instead of a password. Registration is **per-user and opt-in** — turning
the flag on offers the option but never forces anyone to enrol.

The toggle takes effect immediately with no data migration: the underlying routes
are always registered, so flipping the flag only changes whether the option is
offered.

:::note
This flag has **no effect under [SSO-only mode](#sso-only-mode)**. When
`AUTH_SSO_ONLY` routes everyone through an identity provider, that provider owns
authentication, so the app-native passkey option stays hidden.
:::

## SSO-only mode

| Variable        | Default | Effect                                                                 |
| --------------- | ------- | --------------------------------------------------------------------- |
| `AUTH_SSO_ONLY` | `false` | Route **all** access through single sign-on.                          |

Single sign-on — [OpenID Connect](/docs/reference/environment-variables/#single-sign-on-openid-connect)
or [LDAP / Active Directory](/docs/reference/environment-variables/#single-sign-on-ldap--active-directory) —
sits **alongside** password login by default, so a break-glass password account
keeps working during an outage. Set `AUTH_SSO_ONLY=true` to funnel everyone
through the directory instead: Fortify **registration** and the **local-password
login** are disabled. (LDAP bind auth uses the same login form, so it keeps
working — only the local-password path is blocked.)

`AUTH_SSO_ONLY` only takes effect once a provider (OIDC **or** LDAP) is actually
configured, so a stray flag can never lock everyone out of an instance with no
working SSO.

:::caution
With `AUTH_SSO_ONLY=true` there is no local-password fallback — if your directory
is unreachable, no one can sign in. Only turn it on once SSO is verified working,
and keep a way to flip it back (edit `.env` and restart the stack).
:::

## Directory provisioning (SCIM 2.0)

| Variable     | Default   | Effect                                                        |
| ------------ | --------- | ------------------------------------------------------------- |
| `SCIM_TOKEN` | *(blank)* | Mounts a bearer-token SCIM 2.0 endpoint for the IdP to push to. |

Setting `SCIM_TOKEN` turns on the **SCIM 2.0** provisioning endpoint at
`${APP_URL}/scim/v2`, letting your identity provider (Okta, Entra ID, …) create,
update, and **deactivate** accounts automatically as directory membership changes.
A deactivation **tombstones** the account — access is revoked and sessions end,
but history is retained — and it can be reversed by a later `active: true`. Leave
`SCIM_TOKEN` blank and the endpoint is not mounted at all.

See [Environment variables → Directory provisioning (SCIM 2.0)](/docs/reference/environment-variables/#directory-provisioning-scim-20)
for the full setup.

:::caution
The token is a full provisioning credential — treat it like a password, serve
SCIM over HTTPS only, and rotate it if it leaks.
:::

## Gravatar avatars

User avatars are derived from [Gravatar](https://gravatar.com) using the MD5 hash
of each user's email address (so the raw address never appears in the URL). A user
without a Gravatar falls back cleanly to their initials.

| Variable            | Default                            | Effect                                                              |
| ------------------- | ---------------------------------- | ------------------------------------------------------------------ |
| `GRAVATAR_ENABLED`  | `true`                             | Derive avatars from Gravatar. Set `false` for **initials only** and no outbound requests to gravatar.com. |
| `GRAVATAR_URL`      | `https://www.gravatar.com/avatar`  | The Gravatar endpoint. Point it at a mirror to avoid gravatar.com. |
| `GRAVATAR_SIZE`     | `200`                              | Requested square image size, in pixels.                            |
| `GRAVATAR_DEFAULT`  | `404`                              | The `d=` fallback. `404` is what makes users without a Gravatar fall back to initials; `mp`, `identicon`, or a URL are alternatives. |

:::tip
Turning `GRAVATAR_ENABLED=false` is the privacy-conscious choice if you don't want
your instance making per-user requests to gravatar.com — everyone then shows their
initials.
:::

## Activity logging

The Desk records an activity log (audit trail) of notable actions.

| Variable                    | Default | Effect                                              |
| --------------------------- | ------- | --------------------------------------------------- |
| `ACTIVITYLOG_ENABLED`       | `true`  | Records activity to the database. Set `false` to disable logging entirely. |
| `ACTIVITYLOG_BUFFER_ENABLED`| `false` | Buffers log writes and flushes them in a batch (advanced; reduces write volume). |

## Search analytics

| Variable                   | Default | Effect                                             |
| -------------------------- | ------- | -------------------------------------------------- |
| `MEILISEARCH_NO_ANALYTICS` | `true`  | Disables Meilisearch's anonymous usage analytics.  |

The production stack sets this to `true` by default (`MEILI_NO_ANALYTICS`). Set
it to `false` only if you deliberately want to send Meilisearch usage analytics.

## Advanced Reverb options

These are **off** by default and only relevant for larger or multi-node
deployments. Most single-host instances leave them alone.

| Variable                            | Default | Effect                                                              |
| ----------------------------------- | ------- | ------------------------------------------------------------------ |
| `REVERB_SCALING_ENABLED`            | `false` | Enables horizontal scaling of Reverb across multiple servers (Redis pub/sub). |
| `REVERB_APP_RATE_LIMITING_ENABLED`  | `false` | Enables per-connection message rate limiting.                      |
| `REVERB_APP_RATE_LIMIT_TERMINATE`   | `false` | When rate limiting is on, disconnects clients that exceed the limit rather than just throttling. |

## Update checks

| Variable                        | Default          | Effect                                                       |
| ------------------------------- | ---------------- | ------------------------------------------------------------ |
| `UPDATE_CHECK_ENABLED`          | `true`           | Check daily whether a newer stable release is available.     |
| `UPDATE_CHECK_REPOSITORY`       | `emmpaul/the-desk` | The GitHub `owner/repo` to check and link release notes to. |
| `UPDATE_CHECK_CACHE_TTL_HOURS`  | `12`             | How long a successful check is trusted before the next one.  |

Update checks are **on** by default. Once a day a scheduled command asks the
GitHub Releases API for the latest **stable** release (drafts and pre-releases
are ignored) and caches the result. When your instance is behind, every signed-in
user sees a low-key **"update available"** strip in the sidebar, and the running
version appears in **Settings → About this instance** and the user menu. The strip
is dismissible per version — it comes back on the next release.

The check **fails silently**: no network, an air-gapped host, a rate limit, or a
GitHub outage never blocks a request or shows an error — the last known-good
result is kept.

Set `UPDATE_CHECK_ENABLED=false` for an **air-gapped** instance: no outbound
update-check request is ever made, and the UI shows only the running version with
no "update available" claim. Forks can point `UPDATE_CHECK_REPOSITORY` at their
own upstream.

:::note
The check only reads public release metadata. It sends no information about your
instance to GitHub.
:::

## GIF picker (Giphy)

| Variable        | Default   | Effect                                                        |
| --------------- | --------- | ------------------------------------------------------------ |
| `GIPHY_API_KEY` | *(blank)* | Enables the composer's `/gif` [Giphy](https://developers.giphy.com/) picker. |

The `/gif` picker is **off** by default. Setting `GIPHY_API_KEY` (a free key from
developers.giphy.com) turns it on: typing `/gif` in the composer opens a picker
that searches Giphy — trending on an empty query, debounced search as you type,
infinite scroll — and the chosen GIF is sent as a normal message attachment.

Leave the key blank and the feature is **fully hidden**: the `/gif` command is
absent from autocomplete, the picker never appears, and the search/attach
endpoints return **404**. The key is read server-side and never exposed to the
browser.

`GIPHY_CONTENT_RATING` (default `g`) caps the strictest rating Giphy may
return — `g`, `pg`, `pg-13`, or `r`. `g` keeps results workplace-safe; loosen it
for a casual community. See
[Environment variables → GIFs (Giphy)](/docs/reference/environment-variables/#gifs-giphy).

:::note
A sent GIF is **hotlinked** from Giphy's CDN, not stored on your server, so
viewers' browsers fetch it directly from Giphy. See the privacy note under
[Environment variables → GIFs (Giphy)](/docs/reference/environment-variables/#gifs-giphy).
:::

## Demo mode

| Variable    | Default | Effect                                                          |
| ----------- | ------- | -------------------------------------------------------------- |
| `DEMO_MODE` | `false` | Turn the instance into a public, single-shared-account demo.   |

Demo mode is for running a **public playground** off the seeded "Northwind Labs"
workspace (see the `demo:seed` command), where every visitor signs in as the same
account and lands as the workspace **owner**. Because that one account is shared,
the mode adds guard rails so no visitor can lock out, evict, or deface the
workspace for everyone else. It defaults to **off**, and when off it changes
nothing — leave it off on any real deployment.

Set `DEMO_MODE=true` to enable all of the following at once:

- **Destructive owner actions are blocked.** Changing the shared account's email,
  password, or name; enabling two-factor or a passkey; revoking sessions; deleting
  the account or team; renaming the team or editing its slug; transferring
  ownership; and removing or leaving members are all **rejected server-side** and
  their UI controls render disabled with a "Disabled in the demo" tooltip.
- **All outbound email is swallowed.** The mail transport is forced to the
  in-memory `array` driver, so invites, password resets, verification, and
  notifications never leave the host — regardless of your SMTP settings.
- **Writes are rate-limited per IP.** Message sends (~30/min) and attachment
  uploads (~10/min) are throttled by IP address (per-user throttling is useless
  when everyone shares one account). The caps are generous enough that honest
  exploring never trips them.
- **Self-registration is forced off.** `/register` returns **404** regardless of
  [`REGISTRATION_ENABLED`](#open-registration), so a visitor can't create a fresh
  account with its own unguarded personal team and sidestep the rails.
- **The workspace heals hourly.** A scheduled `demo:seed` runs every hour to wipe
  and rebuild "Northwind Labs", undoing whatever visitors changed. Make sure the
  [scheduler](/docs/self-hosting/configuration/) is running.

:::caution
The hourly reset **force-deletes** the demo team and its accounts, so any
in-flight visitor session breaks when it runs — expected for a throwaway demo,
never appropriate for real data.
:::
