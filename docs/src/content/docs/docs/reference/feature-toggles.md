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

## Integrations platform

The integrations platform lets external systems act inside a workspace as **bot
users** through a versioned public REST API at `${APP_URL}/api/v1`, and lets
them subscribe to **outgoing webhooks** so your systems can react to activity.

| Variable                      | Default | Effect                                                              |
| ----------------------------- | ------- | ------------------------------------------------------------------ |
| `INTEGRATIONS_ENABLED`        | `true`  | Enables bot users, the public REST API, and outgoing webhooks. Set `false` to turn the whole surface off — every `/api/v1` route returns **404**, the management UI hides, and no webhooks are delivered. |
| `INTEGRATIONS_API_RATE_LIMIT` | `60`    | Maximum requests **per token, per minute**. Exceeding it returns **429** with a `Retry-After` header. |

The platform is **on** by default. A bot authenticates with a hashed
**Bearer token** minted for it, scoped to fine-grained `resource:action`
abilities (for example `messages:write`, `channels:read`) — each endpoint
enforces exactly the scope it needs, and a token acts only within the channels
its bot belongs to. Set `INTEGRATIONS_ENABLED=false` to disable the feature
entirely; the routes then behave as if they do not exist.

**Incoming webhooks** let an external system post a message into one channel by
POSTing to an unguessable URL — `${APP_URL}/webhooks/incoming/{token}` — where the
opaque token in the URL **is** the credential (Slack-style). Each webhook is bound
to a single bot and channel, its token is stored only as a hash, and it is
individually revocable. The JSON body accepts either a native `{"body": "..."}` or
a Slack-compatible `{"text": "..."}` field (Block Kit is ignored); an optional
HMAC `X-Signature-256` header is honoured when a signing secret is configured.
Incoming webhooks are governed by the same `INTEGRATIONS_ENABLED` toggle — the
endpoint 404s when the platform is off.

The toggle takes effect immediately with no data migration.

### Outgoing webhooks

A bot with the `webhooks:write` scope can register subscriptions (via
`POST /api/v1/webhooks`) that deliver a signed POST to an external URL whenever
a subscribed event happens. See the [outgoing webhooks reference](/docs/reference/webhooks/)
for the event set, payload shapes, and signature verification. Delivery is
tunable:

| Variable                | Default | Effect                                                              |
| ----------------------- | ------- | ------------------------------------------------------------------ |
| `WEBHOOKS_MAX_ATTEMPTS` | `5`     | Attempts per event before giving up, each retried with exponential backoff. |
| `WEBHOOKS_TIMEOUT`      | `5`     | Seconds each delivery request may run before it counts as a failed attempt. |
| `WEBHOOKS_DISABLE_AFTER`| `5`     | Consecutive failed deliveries (with no success in between) after which a subscription is **auto-disabled** and stops delivering. |
| `WEBHOOKS_BLOCK_PRIVATE_URLS` | `true` | **SSRF guard.** Rejects webhook URLs that aren't public `http`/`https` addresses — loopback, private, link-local, and cloud-metadata (`169.254.169.254`) targets, plus `localhost`/`.local`/`.internal` hostnames — both when a subscription is registered and again before every delivery. Before connecting, delivery also resolves the hostname, blocks it if any resolved address is non-public, and pins the connection to the vetted IP (closing DNS-rebinding attacks); deliveries never follow HTTP redirects. Turn off only for a locked-down instance that deliberately targets internal endpoints. |

Webhooks ride on the same `INTEGRATIONS_ENABLED` master switch — turning the
platform off stops all delivery immediately, including jobs already queued.

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
Avatars are fetched by the server and re-served from your own origin (see
[Remote images are proxied](/docs/reference/security/#remote-images-are-proxied)),
so no reader's browser ever talks to gravatar.com. Turning `GRAVATAR_ENABLED=false`
goes further and stops the instance itself making per-user requests — everyone
then shows their initials.
:::

## Activity logging

The Desk records an activity log (audit trail) of notable actions.

| Variable                    | Default | Effect                                              |
| --------------------------- | ------- | --------------------------------------------------- |
| `ACTIVITYLOG_ENABLED`       | `true`  | Records activity to the database. Set `false` to disable logging entirely. |
| `ACTIVITYLOG_BUFFER_ENABLED`| `false` | Buffers log writes and flushes them in a batch (advanced; reduces write volume). |

## Content Security Policy

Every web response carries a `Content-Security-Policy` header — the browser-side
allow-list that limits what injected markup could do. It is **on** by default and
needs no proxy configuration. See
[Security & compliance → Content Security Policy](/docs/reference/security/#content-security-policy)
for the policy itself and the two accepted residuals.

| Variable           | Default | Effect                                                        |
| ------------------ | ------- | ------------------------------------------------------------- |
| `CSP_ENABLED`      | `true`  | Sends the policy. Set `false` only to serve your own from the reverse proxy. |
| `CSP_REPORT_ONLY`  | `false` | Sends it as `Content-Security-Policy-Report-Only`: violations are logged to the browser console but nothing is blocked. |

Report-only is the safe way to try a change: turn it on, browse the app with the
developer console open, fix whatever is reported, then turn it back off. Leaving
it on permanently protects nobody.

### Allow-listing your own origins

If you add a script, stylesheet, image host, API, embedded frame or font
provider of your own, name it in the matching key rather than disabling the
policy. Values are comma-separated and **appended** to the defaults — they can
never remove the script nonce or `'strict-dynamic'`, so an allow-list entry
cannot silently un-harden the app.

| Variable                | Adds to       |
| ----------------------- | ------------- |
| `CSP_EXTRA_SCRIPT_SRC`  | `script-src`  |
| `CSP_EXTRA_STYLE_SRC`   | `style-src`   |
| `CSP_EXTRA_IMG_SRC`     | `img-src`     |
| `CSP_EXTRA_CONNECT_SRC` | `connect-src` |
| `CSP_EXTRA_FRAME_SRC`   | `frame-src`   |
| `CSP_EXTRA_FONT_SRC`    | `font-src`    |

```bash
CSP_EXTRA_SCRIPT_SRC="https://analytics.example.com"
CSP_EXTRA_CONNECT_SRC="https://analytics.example.com"
```

An external font is governed by two directives, because a stylesheet and the
font files it references are different resource types: the host serving the CSS
goes in `CSP_EXTRA_STYLE_SRC`, and the host serving the `@font-face` files goes
in `CSP_EXTRA_FONT_SRC`. Google Fonts splits those across two hosts, so it needs
both:

```bash
CSP_EXTRA_STYLE_SRC="https://fonts.googleapis.com"
CSP_EXTRA_FONT_SRC="https://fonts.gstatic.com"
```

Setting only `CSP_EXTRA_STYLE_SRC` there lets the stylesheet load and then
blocks every `@font-face` file it asks for, so the text still falls back — the
half-configured case is the one that looks mysterious. A provider that serves
both from a single origin needs that origin in both keys; a font referenced from
your own CSS needs only `CSP_EXTRA_FONT_SRC`. The app self-hosts its own fonts,
so reach for this only if you deliberately add a web font of your own — see
[Security → Content Security Policy](/docs/reference/security/#content-security-policy).

:::note
`script-src` uses `'strict-dynamic'`, and browsers that understand it ignore host
allow-lists in that directive. An extra script host therefore only takes effect
for a tag loaded by an already-trusted script. If a third-party snippet has to be
pasted into the page as inline `<script>`, there is no allow-list that reaches
it — set `CSP_ENABLED=false` and serve your own policy from the reverse proxy.
:::

There is deliberately no key that replaces the whole policy: an override that
could drop the nonce would leave a header that looks protective and is not.

## Clickjacking protection

Nothing may embed the app in a frame by default. That closes **clickjacking**:
an attacker loads your instance in an invisible iframe over their own page, and
a signed-in member who thinks they are clicking that page is really clicking
your controls — leaving a workspace, deleting a channel, revoking a token.

| Variable              | Default | Effect                                                                     |
| --------------------- | ------- | -------------------------------------------------------------------------- |
| `CSP_FRAME_ANCESTORS` | `none`  | Who may frame the app. Always sets the CSP `frame-ancestors` directive; also sends `X-Frame-Options` when the value maps to `DENY` or `SAMEORIGIN` and the policy is enforcing. |

Accepted values:

| Value                     | `frame-ancestors`         | `X-Frame-Options` |
| ------------------------- | ------------------------- | ----------------- |
| `none` *(default)*        | `'none'` — nobody          | `DENY`            |
| `self`                    | `'self'` — your own origin | `SAMEORIGIN`      |
| One or more origins       | those origins             | *(not sent)*      |

```bash
# Embed the app in your intranet portal
CSP_FRAME_ANCESTORS="https://portal.example.com"
```

:::note
`X-Frame-Options` has no allow-list form — its `ALLOW-FROM` was never supported
by Chrome and Firefox dropped it — so naming origins sends `frame-ancestors`
alone. Every browser released in the last several years honours it; the legacy
header is only a fallback for the ones that do not.
:::

Both headers ride on `CSP_ENABLED`. Turning the app policy off means you have
taken ownership of these headers at your reverse proxy, so set them there too.
Under `CSP_REPORT_ONLY=true` the directive is reported but not enforced, and
`X-Frame-Options` is withheld — it has no report-only form, so sending it would
enforce the very thing the dry run is meant to only observe.

## HTTPS enforcement (HSTS)

Responses that arrive over HTTPS carry
`Strict-Transport-Security`, which tells the browser to reach the host over
HTTPS only from then on. Without it the first visit, or any later one typed
without a scheme, still goes out as plain HTTP — the window an on-path attacker
uses to strip TLS and read the session cookie before your redirect to HTTPS ever
happens.

| Variable                   | Default    | Effect                                                                 |
| -------------------------- | ---------- | ---------------------------------------------------------------------- |
| `HSTS_ENABLED`             | `true`     | Send the header at all. Turn off only if your reverse proxy sends it.  |
| `HSTS_MAX_AGE`             | `31536000` | Seconds the browser remembers the pin (one year). `0` forgets the host. |
| `HSTS_INCLUDE_SUBDOMAINS`  | `true`     | Extend the pin to every subdomain.                                     |
| `HSTS_PRELOAD`             | `false`    | Add `preload`. See the warning below.                                  |

The defaults send:

```http
Strict-Transport-Security: max-age=31536000; includeSubDomains
```

The header is **never** sent on a request that arrived over plain HTTP, so a LAN
deployment served over `http://` cannot lock itself out of its own hostname.
TLS is detected from your proxy's `X-Forwarded-Proto`, which the app already
trusts — see [Reverse proxy & TLS](/docs/self-hosting/reverse-proxy/).

:::caution
`HSTS_PRELOAD` is effectively irreversible. Submitting a domain to
[hstspreload.org](https://hstspreload.org) bakes it into browsers themselves, it
commits **every** subdomain of the registrable domain to HTTPS, and removal takes
months to reach users. Only enable it if you own the whole domain and intend to
submit it deliberately.
:::

`preload` is only sent when the rest of the policy would actually qualify for
the list — `HSTS_MAX_AGE` of at least `31536000` **and**
`HSTS_INCLUDE_SUBDOMAINS=true`. Set it beside a shorter max-age or with
subdomains excluded and the directive is left off rather than advertising an
intent the policy cannot back.

Turn `HSTS_INCLUDE_SUBDOMAINS` off if a subdomain of your app's host still has to
answer over plain HTTP — the pin would otherwise make it unreachable too.

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

## Polls

| Variable        | Default | Effect                          |
| --------------- | ------- | ------------------------------- |
| `POLLS_ENABLED` | `true`  | Enables the `/poll` builder.    |

Polls are **on** by default. Typing `/poll` in the composer opens a builder —
a question, two to ten options, and toggles for **multiple answers** and an
**anonymous** poll — and posts the poll as a first-class message. Members vote
inline (single- or multiple-choice), tallies update live, and the creator or a
team admin can close a poll to freeze its results.

Set `POLLS_ENABLED=false` to turn the feature **fully off**: the `/poll` command
is absent from autocomplete, the builder never appears, and the create, vote, and
close endpoints return **404**. Existing poll messages render their last-known
tally read-only. See
[Environment variables → Feature toggles](/docs/reference/environment-variables/#feature-toggles).

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
