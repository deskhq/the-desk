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
| `AUTH_SSO_ONLY` | `false` | Route **all** access through your identity provider.                  |

Single sign-on ([OpenID Connect](/docs/reference/environment-variables/#single-sign-on-openid-connect))
sits **alongside** password login by default, so a break-glass password account
keeps working during an IdP outage. Set `AUTH_SSO_ONLY=true` to funnel everyone
through the directory instead: Fortify **registration** and **password login** are
disabled, and the login page shows only the "Sign in with SSO" button.

:::caution
With `AUTH_SSO_ONLY=true` there is no password fallback — if your IdP is
unreachable, no one can sign in. Only turn it on once SSO is verified working,
and keep a way to flip it back (edit `.env` and restart the stack).
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
