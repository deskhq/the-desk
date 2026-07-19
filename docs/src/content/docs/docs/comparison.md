---
title: The Desk vs Slack, Mattermost & Rocket.Chat
description: How The Desk compares to Slack and other self-hosted team chat like Mattermost and Rocket.Chat — an honest look at where a calm, self-hosted, MIT-licensed Slack alternative fits.
---

If you're looking for a **self-hosted Slack alternative**, you've probably found
that the options split into two camps: proprietary SaaS you can't run yourself,
and large open-source platforms that can be a lot to operate. The Desk aims at a
third spot — a small, calm, MIT-licensed chat app you run from **one compose
file**.

This page is an honest look at where it fits. It also tells you plainly where it
*doesn't* yet, so you don't deploy it and hit a wall.

## At a glance

| | **The Desk** | **Slack** | **Mattermost** | **Rocket.Chat** |
| --- | --- | --- | --- | --- |
| Self-hostable | ✅ Yes | ❌ SaaS only | ✅ Yes | ✅ Yes |
| License | MIT (open source) | Proprietary | Source-available / open core | MIT (open core) |
| Price | Free — no per-seat | Free tier, then per-seat | Free tier + paid editions | Free tier + paid editions |
| Setup | One `docker compose up -d` | Sign up | Docker / binary / Helm | Docker / Snap / Helm |
| Data ownership | Your server, always | Slack's cloud | Your server | Your server |
| Footprint | Deliberately small | — | Larger, enterprise-oriented | Larger, very feature-rich |

Slack is the product most teams are leaving; Mattermost and Rocket.Chat are the
established self-hosted alternatives. The Desk is the newcomer optimizing for
*less* — fewer moving parts, less to learn, less to run.

## What The Desk is good at

- **Getting out of your way.** Channels, threads, and DMs plus the workflow
  features teams actually miss: [scheduled messages](/docs/), message reminders,
  [polls](/docs/reference/feature-toggles/#polls), reactions, custom emoji,
  user-uploaded avatars, and instant full-text search.
- **Being trivial to run.** A prebuilt image and a single compose file. No
  Kubernetes, no build step, no exotic dependencies — a small
  [2 vCPU / 2 GB VPS](/docs/self-hosting/requirements/) comfortably hosts a team.
- **Being yours.** MIT-licensed, auditable, self-hosted. Your messages never
  leave your server, every user can export their data, and there's no per-seat
  meter.
- **Directory-managed logins.** [OIDC single
  sign-on](/docs/reference/environment-variables/#single-sign-on-openid-connect)
  with just-in-time provisioning,
  [LDAP / Active Directory](/docs/reference/environment-variables/#single-sign-on-ldap--active-directory)
  bind authentication with directory sync,
  [SCIM 2.0 provisioning](/docs/reference/environment-variables/#directory-provisioning-scim-20)
  for Okta / Entra ID / OneLogin, and an
  [SSO-only mode](/docs/reference/feature-toggles/#sso-only-mode) that turns
  password logins off entirely.
- **Integrations you can build on.** [Bots and a versioned REST
  API](/docs/reference/api/), plus
  [incoming](/docs/reference/incoming-webhooks/) and
  [outgoing](/docs/reference/webhooks/) webhooks, let external systems post into
  a workspace and react to its events.
- **Real admin basics.** Roles, invitations, optional
  [two-factor authentication](/docs/reference/feature-toggles/#two-factor-authentication),
  a moderation audit log, workspace analytics, and device/session management are
  built in.

## When The Desk is *not* the right fit (yet)

Being honest saves you a wasted afternoon. As of **v1.10.1**, The Desk does **not** <!-- x-release-please-version -->
have:

- **Voice or video calls** — not planned for the near term.
- **Native mobile apps** — the web app is fully responsive, but there's no
  dedicated iOS/Android app or push notifications yet.
- **A prebuilt-integrations marketplace** — bots, a [REST
  API](/docs/reference/api/), and [webhooks](/docs/reference/webhooks/) are built
  in, but there's no directory of hundreds of ready-made apps. If your workflow
  depends on one, a bigger platform will serve you better.

If none of those are dealbreakers and you want the lightest self-hosted chat that
still feels finished, The Desk is built for you.

## Ready to try it?

[Deploy The Desk in about five minutes](/docs/self-hosting/installation/) — or
skim the [requirements](/docs/self-hosting/requirements/) first.
