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
  reactions and custom emoji, and instant full-text search.
- **Being trivial to run.** A prebuilt image and a single compose file. No
  Kubernetes, no build step, no exotic dependencies — a small
  [2 vCPU / 2 GB VPS](/docs/self-hosting/requirements/) comfortably hosts a team.
- **Being yours.** MIT-licensed, auditable, self-hosted. Your messages never
  leave your server, every user can export their data, and there's no per-seat
  meter.
- **Real admin basics.** Roles, invitations, a moderation audit log, workspace
  analytics, and device/session management are built in.

## When The Desk is *not* the right fit (yet)

Being honest saves you a wasted afternoon. As of **v1.10.1**, The Desk does **not** <!-- x-release-please-version -->
have:

- **Voice or video calls** — not planned for the near term.
- **SSO / OIDC / LDAP / SCIM** — on the roadmap, not available today. If your org
  *requires* directory-managed logins now, Mattermost or Rocket.Chat are the
  safer pick.
- **Native mobile apps** — the web app is fully responsive, but there's no
  dedicated iOS/Android app or push notifications yet.
- **A large integration/bot marketplace** — if your workflow depends on hundreds
  of prebuilt integrations, a bigger platform will serve you better.

If none of those are dealbreakers and you want the lightest self-hosted chat that
still feels finished, The Desk is built for you.

## Ready to try it?

[Deploy The Desk in about five minutes](/docs/self-hosting/installation/) — or
skim the [requirements](/docs/self-hosting/requirements/) first.
