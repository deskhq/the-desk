---
title: Running a public demo
description: Run The Desk as a public, single-shared-account demo with DEMO_MODE — guard rails, hourly reset, and no outbound mail.
---

`DEMO_MODE` turns an instance into a **public playground**: a single shared
account that anyone can sign in to, landing in the seeded "Northwind Labs"
workspace as its owner. Because everyone shares that one account, the mode adds
guard rails so no visitor can lock out, evict, or deface the workspace for the
next — and an hourly reset heals whatever slips through.

It defaults to **off** and, when off, changes nothing. Never turn it on for an
instance that holds real data — the hourly reset **force-deletes** the workspace
and its accounts.

## Turn it on

Set the flag in `.env` and restart the stack (it is read at runtime — no
rebuild):

```bash
DEMO_MODE=true
```

```bash
docker compose up -d
```

Then seed the demo workspace once:

```bash
docker compose exec app php artisan demo:seed
```

This creates the "Northwind Labs" workspace with the login
`demo@northwind.test` / `demo-password`.

## How visitors get in

Point visitors at your home page. With `DEMO_MODE=true` an **"Enter the demo"**
button appears there and on the login screen; one click signs the visitor into
the shared account and drops them in the seeded workspace, so nobody has to know
or type the credentials. The login screen still lists them in small print for
anyone who wants to sign in by hand.

The button is only rendered while the flag is on, and the endpoint behind it
(`POST /demo/login`) returns **404** when it is off, so a real deployment is left
with no demo entry point at all. Entry is rate-limited per IP, like every other
demo write.

## What DEMO_MODE enforces

Turning the flag on activates all of the following at once:

- **Destructive owner actions are blocked** server-side — changing the shared
  account's email, password, or name; enabling two-factor or a passkey; revoking
  sessions; deleting the account or team; renaming the team or editing its slug;
  transferring ownership; and removing or leaving members. Each control also
  renders disabled in the UI with a "Disabled in the demo" tooltip, and a slim
  banner across the top tells visitors they're on a shared, throwaway workspace.
- **All outbound email is swallowed.** The mail transport is forced to the
  in-memory `array` driver, so invites, password resets, verification, and
  notifications never leave the host — regardless of your SMTP settings.
- **Writes are rate-limited per IP** — demo entry (~10/min), message sends
  (~30/min), and attachment uploads (~10/min), keyed by IP address since every
  visitor shares one account.
- **Self-registration is forced off** regardless of
  [`REGISTRATION_ENABLED`](/reference/feature-toggles/#open-registration), so
  a visitor can't register a fresh unguarded account and sidestep the rails.

## The hourly reset

A scheduled `demo:seed` runs **every hour** to wipe and rebuild the workspace,
undoing whatever visitors changed. It relies on the
[scheduler](/self-hosting/configuration/) running (the bundled stack runs it
for you). You can also reset on demand at any time:

```bash
docker compose exec app php artisan demo:seed
```

:::caution
The reset force-deletes the demo team and its accounts, so any in-flight visitor
session breaks when it runs. That's expected for a throwaway demo and is never
appropriate for real data — keep `DEMO_MODE=false` on a genuine deployment.
:::

See [Feature toggles → Demo mode](/reference/feature-toggles/#demo-mode) for
the toggle reference.
