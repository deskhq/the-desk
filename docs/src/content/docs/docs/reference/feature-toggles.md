---
title: Feature toggles
description: Features you can turn on or off from .env — open registration, activity logging, and advanced Reverb options.
---

Several behaviours are switched from `.env`. Like all settings, they are read at
**runtime** — change the value and restart the stack to apply it, no rebuild
needed:

```bash
docker compose -f docker-compose.prod.yml up -d
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
